<?php

namespace App\Exceptions;

use App\Contracts\AnalyzerUnavailable;

class OpenRouterRetryableStatusException extends NewsAnalysisException implements AnalyzerUnavailable {}
