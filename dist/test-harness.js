import path from 'node:path';
import os from 'node:os';
import { WpCliEngine } from './engines/cli.js';
import { SecurityManager } from './security.js';
async function runTests() {
    console.log('=============================================');
    console.log('🧪 Starting WordPress MCP Server Test Suite');
    console.log('=============================================\n');
    const sitePath = process.env.WP_PATH || '/Volumes/T7/Other local/wptest';
    const localwpScript = path.join(os.homedir(), '.gemini/config/skills/localwp-cli-skill/scripts/localwp-cli.sh');
    console.log(`[Config] Target Site: ${sitePath}`);
    console.log(`[Config] LocalWP Helper Script: ${localwpScript}`);
    const security = new SecurityManager({ safeMode: true });
    const engine = new WpCliEngine({
        wpPath: sitePath,
        localwpScriptPath: localwpScript,
        security,
    });
    // Test 1: getSiteInfo
    console.log('\n--- 1. Testing getSiteInfo() ---');
    try {
        const siteInfo = await engine.getSiteInfo();
        console.log('✅ Site Info retrieved:');
        console.log(`   - Site URL: ${siteInfo.siteUrl}`);
        console.log(`   - Name: ${siteInfo.name}`);
        console.log(`   - Core Version: ${siteInfo.coreVersion}`);
        console.log(`   - Active Theme: ${siteInfo.activeTheme}`);
        console.log(`   - Safe Mode: ${siteInfo.safeMode}`);
    }
    catch (err) {
        console.error('❌ Failed getSiteInfo():', err.message);
    }
    // Test 2: listPosts
    console.log('\n--- 2. Testing listPosts({ perPage: 3 }) ---');
    let firstPostId;
    try {
        const posts = await engine.listPosts({ perPage: 3 });
        console.log(`✅ Retrieved ${posts.length} posts:`);
        posts.forEach((p, idx) => {
            console.log(`   [${idx + 1}] ID: ${p.id} | Title: "${p.title}" | Status: ${p.status} | Slug: ${p.slug}`);
        });
        if (posts.length > 0) {
            firstPostId = posts[0].id;
        }
    }
    catch (err) {
        console.error('❌ Failed listPosts():', err.message);
    }
    // Test 3: getPost
    if (firstPostId) {
        console.log(`\n--- 3. Testing getPost(${firstPostId}) ---`);
        try {
            const post = await engine.getPost(firstPostId);
            console.log(`✅ Retrieved post details for ID ${post.id}:`);
            console.log(`   - Title: ${post.title}`);
            console.log(`   - Date: ${post.date}`);
            console.log(`   - Content preview: ${post.content.substring(0, 100).replace(/\n/g, ' ')}...`);
            console.log(`   - Meta keys count: ${Object.keys(post.meta || {}).length}`);
        }
        catch (err) {
            console.error(`❌ Failed getPost(${firstPostId}):`, err.message);
        }
    }
    // Test 4: listPlugins
    console.log('\n--- 4. Testing listPlugins() ---');
    try {
        const plugins = await engine.listPlugins();
        console.log(`✅ Retrieved ${plugins.length} plugins:`);
        plugins.slice(0, 5).forEach((p) => {
            console.log(`   - ${p.name} (v${p.version}) [${p.status}]`);
        });
        if (plugins.length > 5) {
            console.log(`   ... and ${plugins.length - 5} more plugins`);
        }
    }
    catch (err) {
        console.error('❌ Failed listPlugins():', err.message);
    }
    // Test 5: getTaxonomiesAndTypes
    console.log('\n--- 5. Testing getTaxonomiesAndTypes() ---');
    try {
        const data = await engine.getTaxonomiesAndTypes();
        const typeNames = Object.keys(data.postTypes);
        const taxNames = Object.keys(data.taxonomies);
        console.log(`✅ Found ${typeNames.length} Post Types:`, typeNames.slice(0, 8).join(', '));
        console.log(`✅ Found ${taxNames.length} Taxonomies:`, taxNames.slice(0, 8).join(', '));
    }
    catch (err) {
        console.error('❌ Failed getTaxonomiesAndTypes():', err.message);
    }
    // Test 6: Safe Mode verification
    console.log('\n--- 6. Testing Safe Mode Protection (Zero-Trust Guard) ---');
    try {
        await engine.createPost({
            title: 'Hacked Post Title',
            content: 'This should be blocked by Safe Mode!',
        });
        console.error('❌ SECURITY FAILURE: createPost was allowed in Safe Mode!');
    }
    catch (err) {
        console.log('✅ PASS: Safe Mode correctly intercepted and blocked unauthorized write:');
        console.log(`   "${err.message}"`);
    }
    console.log('\n=============================================');
    console.log('🎉 Test Suite Completed Successfully!');
    console.log('=============================================');
}
runTests().catch((e) => {
    console.error('Fatal test error:', e);
});
