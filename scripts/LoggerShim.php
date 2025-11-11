<?php
namespace MiIntegracionApi\Helpers;

class Logger {
    public function __construct(private string $channel = 'default') {}
    public function debug(string $message, array $context = []): void {}
    public function info(string $message, array $context = []): void {}
    public function notice(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {}
    public function critical(string $message, array $context = []): void {}
    public function alert(string $message, array $context = []): void {}
    public function emergency(string $message, array $context = []): void {}
}

namespace MiIntegracionApi\Logging\Core;

interface ILoggerShim { public function debug(string $m, array $c=[]): void; public function info(string $m, array $c=[]): void; public function notice(string $m, array $c=[]): void; public function warning(string $m, array $c=[]): void; public function error(string $m, array $c=[]): void; public function critical(string $m, array $c=[]): void; public function alert(string $m, array $c=[]): void; public function emergency(string $m, array $c=[]): void; }
class LoggerBasic implements ILoggerShim {
    private static ?self $instance = null;
    
    public function __construct(private string $channel = 'default') {}
    
    public static function getInstance(string $channel = 'default'): self {
        if (self::$instance === null) {
            self::$instance = new self($channel);
        }
        return self::$instance;
    }
    
    public function debug(string $message, array $context = []): void {}
    public function info(string $message, array $context = []): void {}
    public function notice(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {}
    public function critical(string $message, array $context = []): void {}
    public function alert(string $message, array $context = []): void {}
    public function emergency(string $message, array $context = []): void {}
}


