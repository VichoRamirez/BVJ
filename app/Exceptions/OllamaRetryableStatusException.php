<?php

namespace App\Exceptions;

use App\Contracts\AnalyzerUnavailable;

class OllamaRetryableStatusException extends NewsAnalysisException implements AnalyzerUnavailable {}
