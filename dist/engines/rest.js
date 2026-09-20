import axios from 'axios';
export class WpRestEngine {
    client;
    siteUrl;
    security;
    constructor(options) {
        this.siteUrl = options.siteUrl.replace(/\/+$/, '');
        this.security = options.security;
        const headers = {
            'Content-Type': 'application/json',
            'User-Agent': 'wordpress-mcp/1.0.0',
        };
        if (options.username && options.appPassword) {
            const token = Buffer.from(`${options.username}:${options.appPassword}`).toString('base64');
            headers['Authorization'] = `Basic ${token}`;
        }
        this.client = axios.create({
            baseURL: this.siteUrl,
            headers,
            timeout: 30000,
        });
    }
    async getSiteInfo() {
        const res = await this.client.get('/wp-json/');
        const data = res.data;
        return {
            siteUrl: data.url || this.siteUrl,
            homeUrl: data.home || this.siteUrl,
            name: data.name,
            description: data.description,
            adapter: 'rest',
            safeMode: this.security.isSafeMode(),
        };
    }
    async listPosts(params = {}) {
        const endpoint = params.postType === 'page' ? '/wp-json/wp/v2/pages' : '/wp-json/wp/v2/posts';
        const queryParams = {
            per_page: params.perPage || 10,
            page: params.page || 1,
        };
        if (params.status)
            queryParams.status = params.status;
        if (params.search)
            queryParams.search = params.search;
        const res = await this.client.get(endpoint, { params: queryParams });
        return res.data.map((p) => ({
            id: p.id,
            title: p.title?.rendered || '',
            slug: p.slug,
            status: p.status,
            date: p.date,
            type: p.type || params.postType || 'post',
            url: p.link,
        }));
    }
    async getPost(id) {
        const res = await this.client.get(`/wp-json/wp/v2/posts/${id}?context=edit`);
        const p = res.data;
        return {
            id: p.id,
            title: typeof p.title === 'string' ? p.title : p.title?.raw || p.title?.rendered || '',
            slug: p.slug,
            status: p.status,
            date: p.date,
            type: p.type,
            content: typeof p.content === 'string' ? p.content : p.content?.raw || p.content?.rendered || '',
            excerpt: typeof p.excerpt === 'string' ? p.excerpt : p.excerpt?.raw || p.excerpt?.rendered || '',
            categories: p.categories || [],
            tags: p.tags || [],
            meta: p.meta || {},
            url: p.link,
        };
    }
    async createPost(params) {
        this.security.assertWritable('createPost');
        const endpoint = params.postType === 'page' ? '/wp-json/wp/v2/pages' : '/wp-json/wp/v2/posts';
        const payload = {
            title: params.title,
            content: params.content,
            status: params.status || 'draft',
        };
        if (params.excerpt)
            payload.excerpt = params.excerpt;
        if (params.categories)
            payload.categories = params.categories;
        if (params.tags)
            payload.tags = params.tags;
        if (params.meta)
            payload.meta = params.meta;
        const res = await this.client.post(endpoint, payload);
        const p = res.data;
        return {
            id: p.id,
            title: p.title?.rendered || params.title,
            slug: p.slug,
            status: p.status,
            date: p.date,
            type: p.type,
            content: p.content?.rendered || params.content,
            excerpt: p.excerpt?.rendered || params.excerpt,
            categories: p.categories || [],
            tags: p.tags || [],
            meta: p.meta || {},
            url: p.link,
        };
    }
    async updatePost(params) {
        this.security.assertWritable('updatePost');
        const endpoint = `/wp-json/wp/v2/posts/${params.id}`;
        const payload = {};
        if (params.title !== undefined)
            payload.title = params.title;
        if (params.content !== undefined)
            payload.content = params.content;
        if (params.status !== undefined)
            payload.status = params.status;
        if (params.excerpt !== undefined)
            payload.excerpt = params.excerpt;
        if (params.categories !== undefined)
            payload.categories = params.categories;
        if (params.tags !== undefined)
            payload.tags = params.tags;
        if (params.meta !== undefined)
            payload.meta = params.meta;
        const res = await this.client.post(endpoint, payload);
        const p = res.data;
        return {
            id: p.id,
            title: p.title?.rendered || '',
            slug: p.slug,
            status: p.status,
            date: p.date,
            type: p.type,
            content: p.content?.rendered || '',
            excerpt: p.excerpt?.rendered || '',
            categories: p.categories || [],
            tags: p.tags || [],
            meta: p.meta || {},
            url: p.link,
        };
    }
    async listPlugins() {
        try {
            const res = await this.client.get('/wp-json/wp/v2/plugins');
            return res.data.map((p) => ({
                name: p.plugin || p.name,
                status: p.status,
                version: p.version,
            }));
        }
        catch (err) {
            if (err.response?.status === 403 || err.response?.status === 401) {
                throw new Error('Listing plugins via REST API requires WordPress Administrator capabilities (manage_options) and an authenticated Application Password.');
            }
            throw err;
        }
    }
    async getTaxonomiesAndTypes() {
        const [typesRes, taxRes] = await Promise.all([
            this.client.get('/wp-json/wp/v2/types'),
            this.client.get('/wp-json/wp/v2/taxonomies'),
        ]);
        const postTypes = {};
        for (const [key, value] of Object.entries(typesRes.data)) {
            postTypes[key] = {
                name: value.name,
                label: value.name,
                hierarchical: Boolean(value.hierarchical),
                taxonomies: value.taxonomies || [],
            };
        }
        const taxonomies = {};
        for (const [key, value] of Object.entries(taxRes.data)) {
            taxonomies[key] = {
                name: value.name,
                label: value.name,
                hierarchical: Boolean(value.hierarchical),
                postTypes: value.types || [],
            };
        }
        return { postTypes, taxonomies };
    }
    async getDebugLog(_lines) {
        return 'Reading debug.log via remote REST API is disabled for security reasons. Use WP-CLI or SSH adapter for direct server log inspection.';
    }
}
