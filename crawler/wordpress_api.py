"""
WordPress REST API client
"""

import requests


class WordPressAPI:
    def __init__(self, wordpress_url, api_key, logger):
        self.wordpress_url = wordpress_url
        self.api_key = api_key
        self.logger = logger
    
    def test_connection(self):
        """Test connection to WordPress API"""
        try:
            response = requests.get(
                f"{self.wordpress_url}/wp-json/crawler/v1/health",
                timeout=10
            )
            if response.status_code == 200:
                data = response.json()
                return True, data
            return False, f"Status code: {response.status_code}"
        except Exception as e:
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
