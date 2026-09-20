#!/usr/bin/env node
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';
import dotenv from 'dotenv';
import path from 'node:path';
import fs from 'node:fs';
import os from 'node:os';

import { WordPressEngine } from './types.js';
import { SecurityManager } from './security.js';
import { WpCliEngine } from './engines/cli.js';
import { WpRestEngine } from './engines/rest.js';
import { createTools } from './tools.js';

dotenv.config();

// Parse CLI flags and environment variables
function parseConfig() {
  const args = process.argv.slice(2);
  const getArg = (flag: string): string | undefined => {
    const idx = args.indexOf(flag);
    if (idx !== -1 && idx + 1 < args.length) {
      return args[idx + 1];
    }
    return undefined;
  };
  const hasFlag = (flag: string): boolean => args.includes(flag);

  const adapter = (getArg('--adapter') || process.env.WP_ADAPTER || 'cli').toLowerCase() as 'cli' | 'rest';

  // Safe mode is true by default unless --allow-write is passed or SAFE_MODE=false
  let safeMode = true;
  if (hasFlag('--allow-write') || process.env.SAFE_MODE === 'false' || process.env.ALLOW_WRITE === 'true') {
    safeMode = false;
  }
  if (hasFlag('--safe-mode') || process.env.SAFE_MODE === 'true') {
    safeMode = true;
  }

  // CLI path
  const wpPath = getArg('--path') || process.env.WP_PATH || process.cwd();

  // LocalWP script path auto-detection
  const defaultLocalwpScript = path.join(
    os.homedir(),
    '.gemini/config/skills/localwp-cli-skill/scripts/localwp-cli.sh'
  );
  const localwpScriptPath =
    getArg('--localwp-script') ||
    process.env.LOCALWP_SCRIPT_PATH ||
    (fs.existsSync(defaultLocalwpScript) ? defaultLocalwpScript : undefined);

  // REST config
  const siteUrl = getArg('--url') || process.env.WP_SITE_URL || '';
  const username = getArg('--username') || process.env.WP_USERNAME || '';
  const appPassword = getArg('--password') || process.env.WP_APP_PASSWORD || '';

  // Audit log path
  const auditLogPath = getArg('--audit-log') || process.env.AUDIT_LOG_PATH;

  return {
    adapter,
    safeMode,
    wpPath,
    localwpScriptPath,
    siteUrl,
    username,
    appPassword,
    auditLogPath,
  };
}

async function main() {
  const config = parseConfig();

  const security = new SecurityManager({
    safeMode: config.safeMode,
    auditLogPath: config.auditLogPath,
  });

  let engine: WordPressEngine;
  if (config.adapter === 'rest') {
    if (!config.siteUrl) {
      console.error('[ERROR] REST adapter requires WP_SITE_URL or --url.');
      process.exit(1);
    }
    engine = new WpRestEngine({
      siteUrl: config.siteUrl,
      username: config.username,
      appPassword: config.appPassword,
      security,
    });
  } else {
    engine = new WpCliEngine({
      wpPath: config.wpPath,
      localwpScriptPath: config.localwpScriptPath,
      security,
    });
  }

  const server = new Server(
    {
      name: 'wordpress-mcp',
      version: '1.0.0',
    },
    {
      capabilities: {
        tools: {},
      },
    }
  );

  const tools = createTools(engine, security);
  const toolMap = new Map(tools.map((t) => [t.name, t]));

  server.setRequestHandler(ListToolsRequestSchema, async () => {
    return {
      tools: tools.map((t) => ({
        name: t.name,
        description: t.description,
        inputSchema: t.inputSchema,
      })),
    };
  });

  server.setRequestHandler(CallToolRequestSchema, async (request) => {
    const { name, arguments: toolArgs = {} } = request.params;
    const tool = toolMap.get(name);

    if (!tool) {
      throw new Error(`Tool not found: ${name}`);
    }

    try {
      const result = await tool.handler(toolArgs);
      security.logAudit(name, toolArgs, true);
      return {
        content: [
          {
            type: 'text',
            text: typeof result === 'string' ? result : JSON.stringify(result, null, 2),
          },
        ],
      };
    } catch (err: any) {
      security.logAudit(name, toolArgs, false, err.message);
      return {
        isError: true,
        content: [
          {
            type: 'text',
            text: `[WordPress MCP Error] ${err.message}`,
          },
        ],
      };
    }
  });

  const transport = new StdioServerTransport();
  await server.connect(transport);
  // Log startup message to stderr so stdout remains clean for MCP JSON-RPC
  console.error(`[wordpress-mcp] Server running via stdio (adapter: ${config.adapter}, safeMode: ${config.safeMode})`);
}

main().catch((err) => {
  console.error('[wordpress-mcp Fatal Error]', err);
  process.exit(1);
});
