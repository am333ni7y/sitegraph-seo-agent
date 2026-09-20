import fs from 'node:fs';
import path from 'node:path';

export class SecurityManager {
  private safeMode: boolean;
  private auditLogPath?: string;

  constructor(options: { safeMode?: boolean; auditLogPath?: string } = {}) {
    this.safeMode = options.safeMode ?? true;
    this.auditLogPath = options.auditLogPath;
  }

  public isSafeMode(): boolean {
    return this.safeMode;
  }

  public assertWritable(actionName: string): void {
    if (this.safeMode) {
      throw new Error(
        `[SECURITY ERROR] Action "${actionName}" was blocked because WordPress MCP is running in SAFE MODE (Read-Only). ` +
        `To permit write operations (create/update/delete), start the server with SAFE_MODE=false or --allow-write.`
      );
    }
  }

  public sanitizeInput(input: string): string {
    if (typeof input !== 'string') return '';
    // Strip null bytes and control characters (except common whitespace)
    return input.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '');
  }

  public logAudit(action: string, params: Record<string, any>, success: boolean, error?: string): void {
    const record = {
      timestamp: new Date().toISOString(),
      action,
      safeMode: this.safeMode,
      params,
      success,
      error: error || null,
    };

    if (this.auditLogPath) {
      try {
        fs.appendFileSync(this.auditLogPath, JSON.stringify(record) + '\n', 'utf8');
      } catch (err) {
        console.error(`[AUDIT LOG FAILED]`, err);
      }
    }
  }
}
