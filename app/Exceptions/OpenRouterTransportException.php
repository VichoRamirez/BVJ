<?php

namespace App\Exceptions;

use App\Contracts\AnalyzerUnavailable;

class OpenRouterTransportException extends NewsAnalysisException implements AnalyzerUnavailable {}
