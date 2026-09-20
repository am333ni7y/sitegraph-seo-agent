import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';
import fs from 'node:fs';
const execFileAsync = promisify(execFile);
export class WpCliEngine {
    wpPath;
    localwpScriptPath;
    security;
    constructor(options) {
        this.wpPath = path.resolve(options.wpPath);
        this.security = options.security;
        this.localwpScriptPath = options.localwpScriptPath;
    }
    /**
     * Helper to clean stdout from PHP notices/warnings and extract JSON
     */
    cleanAndParseJson(rawOutput) {
        // If output is already clean JSON:
        const trimmed = rawOutput.trim();
        try {
            return JSON.parse(trimmed);
        }
        catch {
            // Find JSON block start [ or { and end ] or }
            const firstBracket = trimmed.indexOf('[');
            const firstBrace = trimmed.indexOf('{');
            let startIdx = -1;
            if (firstBracket !== -1 && firstBrace !== -1) {
                startIdx = Math.min(firstBracket, firstBrace);
            }
            else if (firstBracket !== -1) {
                startIdx = firstBracket;
            }
            else if (firstBrace !== -1) {
                startIdx = firstBrace;
            }
            if (startIdx !== -1) {
                const isArray = trimmed[startIdx] === '[';
                const endChar = isArray ? ']' : '}';
                const lastIdx = trimmed.lastIndexOf(endChar);
                if (lastIdx !== -1 && lastIdx > startIdx) {
                    const candidate = trimmed.substring(startIdx, lastIdx + 1);
                    try {
                        return JSON.parse(candidate);
                    }
                    catch {
                        // pass
                    }
                }
            }
            // Filter lines starting with Warning, Notice, Deprecated, etc.
            const cleanedLines = trimmed
                .split('\n')
                .filter((line) => {
                const l = line.trim();
                return (!l.startsWith('Warning:') &&
                    !l.startsWith('Deprecated:') &&
                    !l.startsWith('Notice:') &&
                    !l.startsWith('PHP Warning:') &&
                    !l.startsWith('PHP Deprecated:') &&
                    !l.startsWith('PHP Notice:') &&
                    !l.includes('Stack trace:') &&
                    !l.startsWith('#'));
            })
                .join('\n')
                .trim();
            return JSON.parse(cleanedLines);
        }
    }
    /**
     * Execute WP-CLI command with arguments
     */
    async runWp(args) {
        try {
            if (this.localwpScriptPath && fs.existsSync(this.localwpScriptPath)) {
                // Run using localwp helper: script <target_path> wp <args>
                const { stdout } = await execFileAsync(this.localwpScriptPath, [this.wpPath, 'wp', ...args], { maxBuffer: 10 * 1024 * 1024 });
                return stdout;
            }
            else {
                // Run standard wp command
                const { stdout } = await execFileAsync('wp', args, {
                    cwd: this.wpPath,
                    maxBuffer: 10 * 1024 * 1024,
                });
                return stdout;
            }
        }
        catch (err) {
            const stderr = err.stderr ? err.stderr.toString() : '';
            const stdout = err.stdout ? err.stdout.toString() : '';
            throw new Error(`WP-CLI execution failed: ${err.message}\nStderr: ${stderr}\nStdout: ${stdout}`);
        }
    }
    async getSiteInfo() {
        const rawUrl = (await this.runWp(['option', 'get', 'siteurl'])).trim();
        const rawHome = (await this.runWp(['option', 'get', 'home'])).trim();
        const siteUrl = rawUrl.split('\n')[0].trim();
        const homeUrl = rawHome.split('\n')[0].trim();
        let name = '';
        let description = '';
        let coreVersion = '';
        let activeTheme = '';
        try {
            name = (await this.runWp(['option', 'get', 'blogname'])).split('\n')[0].trim();
            description = (await this.runWp(['option', 'get', 'blogdescription'])).split('\n')[0].trim();
            coreVersion = (await this.runWp(['core', 'version'])).split('\n')[0].trim();
            activeTheme = (await this.runWp(['theme', 'list', '--status=active', '--field=name'])).split('\n')[0].trim();
        }
        catch {
            // non-fatal
        }
        return {
            siteUrl,
            homeUrl,
            name,
            description,
            coreVersion,
            activeTheme,
            adapter: 'cli',
            safeMode: this.security.isSafeMode(),
        };
    }
    async listPosts(params = {}) {
        const args = ['post', 'list', '--format=json'];
        if (params.postType)
            args.push(`--post_type=${params.postType}`);
        if (params.status)
            args.push(`--post_status=${params.status}`);
        if (params.search)
            args.push(`--s=${params.search}`);
        if (params.perPage)
            args.push(`--posts_per_page=${params.perPage}`);
        if (params.page)
            args.push(`--paged=${params.page}`);
        const raw = await this.runWp(args);
        const items = this.cleanAndParseJson(raw);
        return items.map((p) => ({
            id: Number(p.ID),
            title: p.post_title,
            slug: p.post_name,
            status: p.post_status,
            date: p.post_date,
            type: p.post_type || params.postType || 'post',
            url: p.guid || undefined,
        }));
    }
    async getPost(id) {
        const raw = await this.runWp(['post', 'get', String(id), '--format=json']);
        const post = this.cleanAndParseJson(raw);
        let meta = {};
        try {
            const metaRaw = await this.runWp(['post', 'meta', 'list', String(id), '--format=json']);
            const metaItems = this.cleanAndParseJson(metaRaw);
            for (const item of metaItems) {
                meta[item.meta_key] = item.meta_value;
            }
        }
        catch {
            // meta fetch failed or empty
        }
        return {
            id: Number(post.ID),
            title: post.post_title,
            slug: post.post_name,
            status: post.post_status,
            date: post.post_date,
            type: post.post_type,
            content: post.post_content,
            excerpt: post.post_excerpt,
            meta,
        };
    }
    async createPost(params) {
        this.security.assertWritable('createPost');
        const args = [
            'post',
            'create',
            `--post_title=${params.title}`,
            `--post_content=${params.content}`,
            `--post_type=${params.postType || 'post'}`,
            `--post_status=${params.status || 'draft'}`,
            '--porcelain',
        ];
        if (params.excerpt) {
            args.push(`--post_excerpt=${params.excerpt}`);
        }
        const output = (await this.runWp(args)).trim();
        const createdId = parseInt(output.split('\n').pop() || '0', 10);
        if (!createdId || isNaN(createdId)) {
            throw new Error(`Failed to create post. Output was: ${output}`);
        }
        // Set meta if provided
        if (params.meta) {
            for (const [key, value] of Object.entries(params.meta)) {
                await this.runWp(['post', 'meta', 'update', String(createdId), key, String(value)]);
            }
        }
        return this.getPost(createdId);
    }
    async updatePost(params) {
        this.security.assertWritable('updatePost');
        const args = ['post', 'update', String(params.id)];
        if (params.title !== undefined)
            args.push(`--post_title=${params.title}`);
        if (params.content !== undefined)
            args.push(`--post_content=${params.content}`);
        if (params.status !== undefined)
            args.push(`--post_status=${params.status}`);
        if (params.excerpt !== undefined)
            args.push(`--post_excerpt=${params.excerpt}`);
        await this.runWp(args);
        if (params.meta) {
            for (const [key, value] of Object.entries(params.meta)) {
                await this.runWp(['post', 'meta', 'update', String(params.id), key, String(value)]);
            }
        }
        return this.getPost(params.id);
    }
    async listPlugins() {
        const raw = await this.runWp(['plugin', 'list', '--format=json']);
        const list = this.cleanAndParseJson(raw);
        return list.map((item) => ({
            name: item.name,
            status: item.status,
            version: item.version,
            updateAvailable: item.update === 'available',
        }));
    }
    async getTaxonomiesAndTypes() {
        const typesRaw = await this.runWp(['post-type', 'list', '--format=json']);
        const taxRaw = await this.runWp(['taxonomy', 'list', '--format=json']);
        const typesList = this.cleanAndParseJson(typesRaw);
        const taxList = this.cleanAndParseJson(taxRaw);
        const postTypes = {};
        for (const t of typesList) {
            postTypes[t.name] = {
                name: t.name,
                label: t.label,
                hierarchical: Boolean(t.hierarchical),
                taxonomies: Array.isArray(t.taxonomies) ? t.taxonomies : [],
            };
        }
        const taxonomies = {};
        for (const tx of taxList) {
            taxonomies[tx.name] = {
                name: tx.name,
                label: tx.label,
                hierarchical: Boolean(tx.hierarchical),
                postTypes: Array.isArray(tx.object_type) ? tx.object_type : [],
            };
        }
        return { postTypes, taxonomies };
    }
    async getDebugLog(lines = 50) {
        // Look for debug.log in wp-content or public/wp-content
        const potentialPaths = [
            path.join(this.wpPath, 'wp-content', 'debug.log'),
            path.join(this.wpPath, 'app', 'public', 'wp-content', 'debug.log'),
            path.join(this.wpPath, 'public', 'wp-content', 'debug.log'),
        ];
        let foundPath = '';
        for (const p of potentialPaths) {
            if (fs.existsSync(p)) {
                foundPath = p;
                break;
            }
        }
        if (!foundPath) {
            return 'No debug.log file found. WP_DEBUG_LOG may be disabled or no errors have occurred yet.';
        }
        try {
            const content = fs.readFileSync(foundPath, 'utf8');
            const allLines = content.split('\n');
            const tail = allLines.slice(-lines).join('\n');
            return tail;
        }
        catch (err) {
            return `Failed to read debug.log: ${err.message}`;
        }
    }
}
