import { WordPressEngine, SiteInfo, PostSummary, PostDetail, ListPostsParams, CreatePostParams, UpdatePostParams, PluginInfo, TaxonomiesAndTypes } from '../types.js';
import { SecurityManager } from '../security.js';
export interface WpRestEngineOptions {
    siteUrl: string;
    username?: string;
    appPassword?: string;
    security: SecurityManager;
}
export declare class WpRestEngine implements WordPressEngine {
    private client;
    private siteUrl;
    private security;
    constructor(options: WpRestEngineOptions);
    getSiteInfo(): Promise<SiteInfo>;
    listPosts(params?: ListPostsParams): Promise<PostSummary[]>;
    getPost(id: number): Promise<PostDetail>;
    createPost(params: CreatePostParams): Promise<PostDetail>;
    updatePost(params: UpdatePostParams): Promise<PostDetail>;
    listPlugins(): Promise<PluginInfo[]>;
    getTaxonomiesAndTypes(): Promise<TaxonomiesAndTypes>;
    getDebugLog(_lines?: number): Promise<string>;
}
