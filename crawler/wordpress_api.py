"""
WordPress REST API client
"""

import requests


class WordPressAPI:
    def __init__(self, wordpress_url, api_key, logger):
        self.wordpress_url = wordpress_url
        self.api_key = api_key
        self.logger = logger
        self._connection_tested = False  # Cache connection test result
        self._connection_ok = False
    
    def test_connection(self, force=False):
        """Test connection to WordPress API (cached after first success)"""
        # Use cached result unless force=True
        if self._connection_tested and not force:
            return self._connection_ok, {'cached': True}
        
        try:
            response = requests.get(
                f"{self.wordpress_url}/wp-json/crawler/v1/health",
                timeout=10
            )
            if response.status_code == 200:
                data = response.json()
                self._connection_tested = True
                self._connection_ok = True
                return True, data
            self._connection_tested = True
            self._connection_ok = False
            return False, f"Status code: {response.status_code}"
        except Exception as e:
            self._connection_tested = True
            self._connection_ok = False
            return False, str(e)
    
    def create_story(self, story_data):
        """Create or get existing story in WordPress"""
        response = requests.post(
            f"{self.wordpress_url}/wp-json/crawler/v1/story",
            headers={'X-API-Key': self.api_key},
            json=story_data,
            timeout=30
        )
        
        if response.status_code in [200, 201]:
            result = response.json()
            return {
                'id': result.get('story_id'),
                'existed': result.get('existed', False)
            }
        else:
            raise Exception(f"Failed to create story: {response.status_code} - {response.text}")
    
    def get_story_chapter_status(self, story_id, total_chapters):
        """Get bulk status of all chapters for a story (FAST!)"""
        try:
            response = requests.get(
                f"{self.wordpress_url}/wp-json/crawler/v1/story/{story_id}/chapters",
                headers={'X-API-Key': self.api_key},
                params={'total_chapters': total_chapters},
                timeout=15
            )
            
            if response.status_code == 200:
                result = response.json()
                return {
                    'success': True,
                    'chapters_count': result.get('chapters_count', 0),
                    'is_complete': result.get('is_complete', False),
                    'existing_chapters': result.get('existing_chapters', [])  # List of chapter numbers
                }
            else:
                # Fallback to individual checks
                return {'success': False, 'chapters_count': 0, 'is_complete': False, 'existing_chapters': []}
        except:
            # Fallback to individual checks
            return {'success': False, 'chapters_count': 0, 'is_complete': False, 'existing_chapters': []}
    
    def check_chapter_exists(self, story_id, chapter_number):
        """Check if chapter already exists in WordPress"""
        try:
            response = requests.get(
                f"{self.wordpress_url}/wp-json/crawler/v1/chapter/exists",
                headers={'X-API-Key': self.api_key},
                params={'story_id': story_id, 'chapter_number': chapter_number},
                timeout=10
            )
            
            if response.status_code == 200:
                result = response.json()
                return {
                    'exists': result.get('exists', False),
                    'chapter_id': result.get('chapter_id')
                }
            else:
                # If endpoint doesn't exist, fallback to False (will crawl)
                return {'exists': False, 'chapter_id': None}
        except:
            # On error, assume doesn't exist (safer to crawl)
            return {'exists': False, 'chapter_id': None}
    
    def create_chapter(self, chapter_data):
        """Create chapter in WordPress"""
        response = requests.post(
            f"{self.wordpress_url}/wp-json/crawler/v1/chapter",
            headers={'X-API-Key': self.api_key},
            json=chapter_data,
            timeout=30
        )
        
        if response.status_code in [200, 201]:
            result = response.json()
            return {
                'id': result.get('chapter_id'),
                'existed': result.get('existed', False)
            }
        else:
            raise Exception(f"Failed to create chapter: {response.status_code} - {response.text}")
    
    def create_chapters_bulk(self, chapters_data):
        """Create multiple chapters in a single API call (OPTIMIZATION)"""
        try:
            response = requests.post(
                f"{self.wordpress_url}/wp-json/crawler/v1/chapters/bulk",
                headers={'X-API-Key': self.api_key},
                json={'chapters': chapters_data},
                timeout=120  # Longer timeout for bulk operations
            )
            
            if response.status_code in [200, 201]:
                result = response.json()
                return {
                    'success': True,
                    'results': result.get('results', []),
                    'created': result.get('created', 0),
                    'existed': result.get('existed', 0),
                    'failed': result.get('failed', 0)
                }
            else:
                # Fallback to individual creation
                return {'success': False, 'error': response.text}
        except Exception as e:
            # Fallback to individual creation
            return {'success': False, 'error': str(e)}
