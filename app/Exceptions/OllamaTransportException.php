<?php

namespace App\Exceptions;

use App\Contracts\AnalyzerUnavailable;

class OllamaTransportException extends NewsAnalysisException implements AnalyzerUnavailable {}
