"""
Configuration loader
"""

import json


def load_config(config_path='config.json'):
    """Load configuration from JSON file"""
    with open(config_path, 'r', encoding='utf-8') as f:
        return json.load(f)
