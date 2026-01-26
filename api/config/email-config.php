<?php
class EmailConfig {
    const IMAP_HOST = 'email-ssl.com.br';
    const IMAP_PORT = 993;
    const IMAP_USERNAME = 'suporte@crprs.org.br';
    const IMAP_PASSWORD = '***REMOVIDO***';
    const SMTP_HOST = 'email-ssl.com.br';
    const SMTP_PORT = 465;
    const SMTP_USERNAME = 'suporte@crprs.org.br';
    const SMTP_PASSWORD = '***REMOVIDO***';
    const FROM_EMAIL = 'suporte@crprs.org.br';
    const FROM_NAME = 'Helpdesk CRPRS';
    const MAX_ANEXOS_EMAIL = 5;
    const MAX_TAMANHO_EMAIL = 20 * 1024 * 1024;
    const TIPOS_PERMITIDOS = ['pdf', 'doc', 'docx', 'txt', 'csv', 'xls', 'xlsx', 'png', 'jpg', 'jpeg'];
    const INTERVALO_VERIFICACAO = 1;
}
