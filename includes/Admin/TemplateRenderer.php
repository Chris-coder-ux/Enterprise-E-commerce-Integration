<?php

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

use Psr\Log\LoggerInterface;

class TemplateRenderer
{
    private LoggerInterface $logger;
    private string $templatesDir;
    private array $pageHandlers;
    
    public function __construct(
        LoggerInterface $logger, 
        string $templatesDir, 
        array $pageHandlers = []
    ) {
        $this->logger = $logger;
        $this->templatesDir = $templatesDir;
        $this->pageHandlers = $pageHandlers;
    }
    
    public function renderPage(string $pageSlug): void
    {
        if (isset($this->pageHandlers[$pageSlug])) {
            $this->renderWithHandler($pageSlug);
        } else {
            $this->renderWithTemplate($pageSlug);
        }
    }
    
    private function renderWithHandler(string $pageSlug): void
    {
        $handler = $this->pageHandlers[$pageSlug];
        if (is_callable($handler)) {
            $handler();
        } elseif (class_exists($handler)) {
            call_user_func([$handler, 'render']);
        }
    }
    
    private function renderWithTemplate(string $templateName): void
    {
        $templatePath = $this->templatesDir . $templateName . '.php';
        
        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            $this->logger->error("Template not found: $templatePath");
            echo '<div class="notice notice-error"><p>' . 
                 esc_html__('Error: No se encontró la plantilla de la página.', 'mi-integracion-api') . 
                 '</p></div>';
        }
    }
}
