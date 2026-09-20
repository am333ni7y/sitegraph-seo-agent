import { z } from 'zod';
import { WordPressEngine } from './types.js';
import { SecurityManager } from './security.js';

export interface ToolDefinition {
  name: string;
  description: string;
  inputSchema: Record<string, any>;
  handler: (args: any) => Promise<any>;
}

export function createTools(engine: WordPressEngine, security: SecurityManager): ToolDefinition[] {
  return [
    {
      name: 'wp_get_site_info',
      description: 'Get WordPress site metadata, core version, active theme, PHP version, and current MCP security mode.',
      inputSchema: {
        type: 'object',
        properties: {},
      },
      handler: async () => {
        return await engine.getSiteInfo();
      },
    },
    {
      name: 'wp_list_posts',
      description: 'Search and list WordPress posts or pages with filtering by post type, status, search keyword, and pagination.',
      inputSchema: {
        type: 'object',
        properties: {
          postType: {
            type: 'string',
            description: 'Post type to query (e.g., "post", "page", or custom post type slug). Default is "post".',
          },
          status: {
            type: 'string',
            description: 'Post status filter (e.g., "publish", "draft", "any", "pending"). Default is "publish".',
          },
          search: {
            type: 'string',
            description: 'Keyword to search inside post title and content.',
          },
          perPage: {
            type: 'number',
            description: 'Number of posts to return (default 10, max 100).',
          },
          page: {
            type: 'number',
            description: 'Page number for pagination (default 1).',
          },
        },
      },
      handler: async (args) => {
        return await engine.listPosts({
          postType: args.postType,
          status: args.status,
          search: args.search,
          perPage: args.perPage ? Math.min(Number(args.perPage), 100) : 10,
          page: args.page ? Number(args.page) : 1,
        });
      },
    },
    {
      name: 'wp_get_post',
      description: 'Get detailed information and full content of a specific WordPress post or page by ID, including metadata and taxonomies.',
      inputSchema: {
        type: 'object',
        required: ['id'],
        properties: {
          id: {
            type: 'number',
            description: 'The unique WordPress post ID.',
          },
        },
      },
      handler: async (args) => {
        const id = Number(args.id);
        if (!id || isNaN(id)) {
          throw new Error('Valid numeric post ID is required.');
        }
        return await engine.getPost(id);
      },
    },
    {
      name: 'wp_create_post',
      description: 'Create a new WordPress post or page. BLOCKED in Safe Mode unless SAFE_MODE=false is explicitly configured.',
      inputSchema: {
        type: 'object',
        required: ['title', 'content'],
        properties: {
          title: {
            type: 'string',
            description: 'Post title.',
          },
          content: {
            type: 'string',
            description: 'Post body content in HTML, Markdown, or Gutenberg blocks.',
          },
          postType: {
            type: 'string',
            description: 'Post type slug (default "post").',
          },
          status: {
            type: 'string',
            enum: ['publish', 'draft', 'pending', 'private'],
            description: 'Post status (default "draft").',
          },
          excerpt: {
            type: 'string',
            description: 'Optional post excerpt or summary.',
          },
          categories: {
            type: 'array',
            items: { type: 'number' },
            description: 'Array of category IDs.',
          },
          tags: {
            type: 'array',
            items: { type: 'number' },
            description: 'Array of tag IDs.',
          },
          meta: {
            type: 'object',
            description: 'Key-value pairs of custom post meta fields.',
          },
        },
      },
      handler: async (args) => {
        return await engine.createPost({
          title: security.sanitizeInput(args.title),
          content: args.content,
          postType: args.postType || 'post',
          status: args.status || 'draft',
          excerpt: args.excerpt ? security.sanitizeInput(args.excerpt) : undefined,
          categories: args.categories,
          tags: args.tags,
          meta: args.meta,
        });
      },
    },
    {
      name: 'wp_update_post',
      description: 'Update an existing WordPress post or page (content, title, status, or metadata). BLOCKED in Safe Mode unless SAFE_MODE=false is explicitly configured.',
      inputSchema: {
        type: 'object',
        required: ['id'],
        properties: {
          id: {
            type: 'number',
            description: 'The unique WordPress post ID to update.',
          },
          title: {
            type: 'string',
            description: 'New post title.',
          },
          content: {
            type: 'string',
            description: 'New post content.',
          },
          status: {
            type: 'string',
            enum: ['publish', 'draft', 'pending', 'private'],
            description: 'New post status.',
          },
          excerpt: {
            type: 'string',
            description: 'New post excerpt.',
          },
          meta: {
            type: 'object',
            description: 'Key-value pairs of post meta fields to update.',
          },
        },
      },
      handler: async (args) => {
        const id = Number(args.id);
        if (!id || isNaN(id)) {
          throw new Error('Valid numeric post ID is required.');
        }
        return await engine.updatePost({
          id,
          title: args.title ? security.sanitizeInput(args.title) : undefined,
          content: args.content,
          status: args.status,
          excerpt: args.excerpt ? security.sanitizeInput(args.excerpt) : undefined,
          meta: args.meta,
        });
      },
    },
    {
      name: 'wp_list_plugins',
      description: 'Inspect all installed plugins, their active/inactive status, version numbers, and update availability.',
      inputSchema: {
        type: 'object',
        properties: {},
      },
      handler: async () => {
        return await engine.listPlugins();
      },
    },
    {
      name: 'wp_get_taxonomies_and_types',
      description: 'List all registered Custom Post Types (CPTs) and Taxonomies with their hierarchical settings and associations. Essential for theme/plugin development context.',
      inputSchema: {
        type: 'object',
        properties: {},
      },
      handler: async () => {
        return await engine.getTaxonomiesAndTypes();
      },
    },
    {
      name: 'wp_get_debug_log',
      description: 'Read the most recent entries from WordPress debug.log to assist with debugging PHP notices, warnings, and fatal errors.',
      inputSchema: {
        type: 'object',
        properties: {
          lines: {
            type: 'number',
            description: 'Number of lines from end of log file to retrieve (default 50, max 200).',
          },
        },
      },
      handler: async (args) => {
        const lines = args.lines ? Math.min(Number(args.lines), 200) : 50;
        return await engine.getDebugLog(lines);
      },
    },
  ];
}
