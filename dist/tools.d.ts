import { WordPressEngine } from './types.js';
import { SecurityManager } from './security.js';
export interface ToolDefinition {
    name: string;
    description: string;
    inputSchema: Record<string, any>;
    handler: (args: any) => Promise<any>;
}
export declare function createTools(engine: WordPressEngine, security: SecurityManager): ToolDefinition[];
