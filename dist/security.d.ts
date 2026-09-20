export declare class SecurityManager {
    private safeMode;
    private auditLogPath?;
    constructor(options?: {
        safeMode?: boolean;
        auditLogPath?: string;
    });
    isSafeMode(): boolean;
    assertWritable(actionName: string): void;
    sanitizeInput(input: string): string;
    logAudit(action: string, params: Record<string, any>, success: boolean, error?: string): void;
}
