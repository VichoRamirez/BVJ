<?php

namespace App\Exceptions;

use App\Contracts\AnalyzerUnavailable;

class OllamaConfigurationException extends NewsAnalysisException implements AnalyzerUnavailable {}
