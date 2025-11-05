# GetNovels WordPress Plugin

## Overview
WordPress plugin that crawls novels from xbanxia.cc and imports them into Fictioneer theme as stories with chapters.

## Architecture
- **WordPress Plugin**: REST API endpoints for receiving crawled content
- **External Python Crawler**: Standalone scripts that crawl novels and post via REST API
- **Authentication**: API key-based (auto-generated in WordPress admin)

## Quick Start

### 1. WordPress Setup
1. Activate plugin in WordPress admin
2. Go to **Novel Crawler** menu
3. Copy the API key displayed on the page

### 2. Python Crawler Setup
```bash
cd crawler/
pip install -r requirements.txt
```

Edit `config.json`:
```json
{
  "wordpress_url": "http://your-site.local",
  "api_key": "your-api-key-from-wordpress",
  "google_project_id": "your-google-cloud-project",
  "max_chapters_per_run": 10,
  "translate": true
}
```

### 3. Google Cloud Translation (Optional)
To enable translation, set environment variable:
```bash
# Windows (PowerShell)
$env:GOOGLE_APPLICATION_CREDENTIALS="C:\path\to\service-account-key.json"

# Windows (CMD)
set GOOGLE_APPLICATION_CREDENTIALS=C:\path\to\service-account-key.json

# Linux/Mac
export GOOGLE_APPLICATION_CREDENTIALS="/path/to/service-account-key.json"
```

### 4. Run Crawler
```bash
python crawler.py https://www.xbanxia.cc/books/396941.html
```

## File Structure

### WordPress Plugin
```
getnovels/
├── getnovels-crawler.php          # Main plugin file
├── includes/
│   ├── class-crawler-rest-api.php # REST API endpoints
│   ├── class-crawler-logger.php   # Logging system
│   └── class-crawler-admin.php    # Admin interface
└── assets/
    ├── css/admin.css
    └── js/admin.js
```

### Python Crawler (Modular)
```
crawler/
├── crawler.py           # Main orchestrator
├── parser.py           # HTML parsing for xbanxia.cc
├── translator.py       # Google Cloud Translate integration
├── wordpress_api.py    # WordPress REST API client
├── file_manager.py     # File operations
├── config_loader.py    # Configuration loader
├── config.json         # Configuration file
├── requirements.txt    # Python dependencies
└── novels/             # Crawled content storage
    └── novel_{id}/
        ├── metadata.json
        ├── chapters_raw/      # Original Chinese
        └── chapters_translated/ # English translations
```

## REST API Endpoints

### Health Check (Public)
```
GET /wp-json/crawler/v1/health
```

### Create Story (Requires API Key)
```
POST /wp-json/crawler/v1/story
Headers: X-API-Key: your-api-key
Body: {
  "url": "https://...",
  "title": "Story Title (EN)",
  "title_zh": "故事标题",
  "author": "Author Name",
  "description": "Story description...",
  "cover_url": "https://..."
}
```

### Create Chapter (Requires API Key)
```
POST /wp-json/crawler/v1/chapter
Headers: X-API-Key: your-api-key
Body: {
  "url": "https://...",
  "story_id": 123,
  "title": "Chapter Title (EN)",
  "title_zh": "章节标题",
  "content": "Chapter content...",
  "chapter_number": 1
}
```

## Configuration Options

### config.json
- `wordpress_url`: WordPress site URL
- `api_key`: API key from WordPress admin
- `google_project_id`: Google Cloud project ID
- `max_chapters_per_run`: Limit chapters per execution (0 = no limit)
- `delay_between_requests`: Seconds between requests (avoid rate limiting)
- `translate`: Enable/disable translation (true/false)
- `target_language`: Translation target language (default: "en")

## How It Works

1. **Crawl Novel Page**: Extract metadata (title, author, description, cover, chapter list)
2. **Translate Metadata**: Optional translation using Google Cloud Translate
3. **Create Story**: POST to WordPress REST API
4. **Process Chapters**: For each chapter:
   - Fetch chapter page
   - Extract content from `<div id="nr1">`
   - Translate content (optional)
   - Save to local files (raw + translated)
   - POST to WordPress REST API
5. **WordPress**: Creates `fcn_story` and `fcn_chapter` posts automatically

## xbanxia.cc HTML Structure

### Novel Page
```html
<div class="book-intro">
  <h1>Title</h1>
  <img class="lazy" data-original="cover.jpg">
  <div class="book-describe">
    <p>作者︰<a>Author</a></p>
    <p>狀態︰Status</p>
    <div class="describe-html">Description</div>
  </div>
</div>
<div class="book-list">
  <ul>
    <li><a href="/books/123/456.html">Chapter 1</a></li>
  </ul>
</div>
```

### Chapter Page
```html
<h1 id="nr_title">Chapter Title</h1>
<div id="nr1">
  Chapter content here...
</div>
```

## Troubleshooting

### Translation Not Working
1. Install Google Cloud Translate: `pip install --upgrade google-cloud-translate`
2. Set credentials: `$env:GOOGLE_APPLICATION_CREDENTIALS="path\to\key.json"`
3. Verify project ID in `config.json`

### API Connection Failed
1. Check WordPress site is running
2. Verify `wordpress_url` in config.json
3. Confirm API key is correct (copy from WordPress admin)

### Chapters Not Creating
1. Check `novels/novel_{id}/chapters_raw/` for saved content
2. Review WordPress admin → Novel Crawler → Logs
3. Verify Fictioneer theme is active

## Development

### Adding New Novel Source
1. Create new parser in `parser.py` or new file
2. Update `parse_novel_page()` and `parse_chapter_page()` methods
3. Adjust HTML selectors for new site structure

### Modifying Translation
Edit `translator.py` to:
- Change translation service
- Adjust chunk sizes
- Modify language detection

### Custom WordPress Fields
Edit `includes/class-crawler-rest-api.php`:
- Add new parameters to endpoint schemas
- Store additional metadata with `update_post_meta()`
