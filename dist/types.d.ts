export interface SiteInfo {
    siteUrl: string;
    homeUrl: string;
    name?: string;
    description?: string;
    coreVersion?: string;
    phpVersion?: string;
    activeTheme?: string;
    adapter: 'cli' | 'rest';
    safeMode: boolean;
}
export interface PostSummary {
    id: number;
    title: string;
    slug: string;
    status: string;
    date: string;
    type: string;
    url?: string;
}
export interface PostDetail extends PostSummary {
    content: string;
    excerpt?: string;
    categories?: (number | string)[];
    tags?: (number | string)[];
    meta?: Record<string, any>;
}
export interface ListPostsParams {
    postType?: string;
    status?: string;
    search?: string;
    perPage?: number;
    page?: number;
}
export interface CreatePostParams {
    title: string;
    content: string;
    postType?: string;
    status?: 'publish' | 'draft' | 'pending' | 'private';
    excerpt?: string;
    categories?: number[];
    tags?: number[];
    meta?: Record<string, any>;
}
export interface UpdatePostParams {
    id: number;
    title?: string;
    content?: string;
    status?: 'publish' | 'draft' | 'pending' | 'private';
    excerpt?: string;
    categories?: number[];
    tags?: number[];
    meta?: Record<string, any>;
}
export interface PluginInfo {
    name: string;
    status: 'active' | 'inactive' | 'must-use' | 'drop-in';
    version: string;
    updateAvailable?: boolean;
}
export interface TaxonomiesAndTypes {
    postTypes: Record<string, {
        name: string;
        label: string;
        hierarchical: boolean;
        taxonomies: string[];
    }>;
    taxonomies: Record<string, {
        name: string;
        label: string;
        hierarchical: boolean;
        postTypes: string[];
    }>;
}
export interface WordPressEngine {
    getSiteInfo(): Promise<SiteInfo>;
    listPosts(params?: ListPostsParams): Promise<PostSummary[]>;
    getPost(id: number): Promise<PostDetail>;
    createPost(params: CreatePostParams): Promise<PostDetail>;
    updatePost(params: UpdatePostParams): Promise<PostDetail>;
    listPlugins(): Promise<PluginInfo[]>;
    getTaxonomiesAndTypes(): Promise<TaxonomiesAndTypes>;
    getDebugLog(lines?: number): Promise<string>;
}
export interface AppConfig {
    adapter: 'cli' | 'rest';
    safeMode: boolean;
    debug: boolean;
    wpPath?: string;
    localwpScriptPath?: string;
    siteUrl?: string;
    username?: string;
    appPassword?: string;
}
