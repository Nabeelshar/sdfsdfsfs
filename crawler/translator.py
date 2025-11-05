"""
Translation module for Google Cloud Translate API
"""

# Try to import Google Cloud Translate
try:
    from google.cloud import translate_v3
    TRANSLATOR_AVAILABLE = True
    TRANSLATOR_VERSION = 'v3'
except ImportError:
    try:
        from google.cloud import translate_v2 as translate
        TRANSLATOR_AVAILABLE = True
        TRANSLATOR_VERSION = 'v2'
    except ImportError:
        TRANSLATOR_AVAILABLE = False
        TRANSLATOR_VERSION = None


class Translator:
    def __init__(self, project_id, logger, credentials_file=None):
        self.project_id = project_id
        self.logger = logger
        self.client = None
        self.version = None
        
        if not TRANSLATOR_AVAILABLE:
            self.logger("Warning: google-cloud-translate not available. Translation will be disabled.")
            return
        
        # Set credentials if provided
        if credentials_file:
            import os
            os.environ['GOOGLE_APPLICATION_CREDENTIALS'] = credentials_file
            self.logger(f"Using credentials from: {credentials_file}")
            
        try:
            if TRANSLATOR_VERSION == 'v3':
                self.client = translate_v3.TranslationServiceClient()
                self.version = 'v3'
                self.logger(f"Using Google Cloud Translation API v3 with project: {project_id}")
            else:
                self.client = translate.Client()
                self.version = 'v2'
                self.logger("Using Google Cloud Translation API v2")
        except Exception as e:
            self.logger(f"Warning: Could not initialize Google Translate client: {e}")
            self.client = None
    
    def translate(self, text, source_lang='zh-CN', target_lang='en'):
        """Translate text using Google Cloud Translate API"""
        if not self.client or not text:
            return text
        
        try:
            if self.version == 'v3':
                return self._translate_v3(text, source_lang, target_lang)
            else:
                return self._translate_v2(text, source_lang, target_lang)
        except Exception as e:
            self.logger(f"Translation error: {e}")
            return text
    
    def _translate_v3(self, text, source_lang, target_lang):
        """Translate using Translation API v3 (Advanced)"""
        parent = f"projects/{self.project_id}/locations/global"
        max_length = 30000
        
        if len(text) <= max_length:
            response = self.client.translate_text(
                contents=[text],
                parent=parent,
                mime_type="text/plain",
                source_language_code=source_lang,
                target_language_code=target_lang,
            )
            return response.translations[0].translated_text
        else:
            return self._translate_chunks(text, max_length, source_lang, target_lang, use_v3=True, parent=parent)
    
    def _translate_v2(self, text, source_lang, target_lang):
        """Translate using Translation API v2 (Basic)"""
        max_length = 5000
        
        if len(text) <= max_length:
            result = self.client.translate(
                text,
                source_language=source_lang,
                target_language=target_lang
            )
            return result['translatedText']
        else:
            return self._translate_chunks(text, max_length, source_lang, target_lang, use_v3=False)
    
    def _translate_chunks(self, text, max_length, source_lang, target_lang, use_v3=False, parent=None):
        """Split text into chunks and translate"""
        paragraphs = text.split('\n\n')
        translated_paragraphs = []
        current_chunk = []
        current_length = 0
        
        for para in paragraphs:
            if current_length + len(para) > max_length and current_chunk:
                chunk_text = '\n\n'.join(current_chunk)
                
                if use_v3:
                    response = self.client.translate_text(
                        contents=[chunk_text],
                        parent=parent,
                        mime_type="text/plain",
                        source_language_code=source_lang,
                        target_language_code=target_lang,
                    )
                    translated_paragraphs.append(response.translations[0].translated_text)
                else:
                    result = self.client.translate(
                        chunk_text,
                        source_language=source_lang,
                        target_language=target_lang
                    )
                    translated_paragraphs.append(result['translatedText'])
                
                current_chunk = [para]
                current_length = len(para)
            else:
                current_chunk.append(para)
                current_length += len(para)
        
        # Translate remaining chunk
        if current_chunk:
            chunk_text = '\n\n'.join(current_chunk)
            
            if use_v3:
                response = self.client.translate_text(
                    contents=[chunk_text],
                    parent=parent,
                    mime_type="text/plain",
                    source_language_code=source_lang,
                    target_language_code=target_lang,
                )
                translated_paragraphs.append(response.translations[0].translated_text)
            else:
                result = self.client.translate(
                    chunk_text,
                    source_language=source_lang,
                    target_language=target_lang
                )
                translated_paragraphs.append(result['translatedText'])
        
        return '\n\n'.join(translated_paragraphs)
