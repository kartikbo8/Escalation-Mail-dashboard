<?php
/**
 * Optional Google Gemini mail classification settings.
 * Set these as environment variables; do not commit API keys here.
 */
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash-lite');