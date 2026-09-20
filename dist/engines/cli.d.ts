import { WordPressEngine, SiteInfo, PostSummary, PostDetail, ListPostsParams, CreatePostParams, UpdatePostParams, PluginInfo, TaxonomiesAndTypes } from '../types.js';
import { SecurityManager } from '../security.js';
export interface WpCliEngineOptions {
    wpPath: string;
    localwpScriptPath?: string;
    security: SecurityManager;
}
export declare class WpCliEngine implements WordPressEngine {
    private wpPath;
    private localwpScriptPath?;
    private security;
    constructor(options: WpCliEngineOptions);
    /**
     * Helper to clean stdout from PHP notices/warnings and extract JSON
     */
    private cleanAndParseJson;
    /**
     * Execute WP-CLI command with arguments
     */
    runWp(args: string[]): Promise<string>;
    getSiteInfo(): Promise<SiteInfo>;
    listPosts(params?: ListPostsParams): Promise<PostSummary[]>;
    getPost(id: number): Promise<PostDetail>;
    createPost(params: CreatePostParams): Promise<PostDetail>;
    updatePost(params: UpdatePostParams): Promise<PostDetail>;
    listPlugins(): Promise<PluginInfo[]>;
    getTaxonomiesAndTypes(): Promise<TaxonomiesAndTypes>;
    getDebugLog(lines?: number): Promise<string>;
}
