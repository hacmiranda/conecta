<?php
/**
 * Script para verificar emails perdidos
 * Executa uma varredura completa na caixa de entrada
 */

require_once '../config/environment.php';
require_once '../config/database.php';
require_once '../config/email-config.php';
require_once '../services/email-service.php';

$log_file = '../../backend/logs/verificacao-manual.log';

function log_msg($msg) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $msg\n", FILE_APPEND);
    echo "<p>$msg</p>";
}

echo "<h2>🔍 Verificação Manual de Emails Perdidos</h2>";
log_msg("=== INICIANDO VERIFICAÇÃO MANUAL ===");

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $emailService = new EmailService($db);
    
    // Conectar ao IMAP
    $mailbox = "{" . EmailConfig::get('IMAP_HOST') . ":" . EmailConfig::get('IMAP_PORT') . "/imap/ssl/novalidate-cert}INBOX";
    log_msg("Conectando: $mailbox");
    
    $inbox = @imap_open($mailbox, EmailConfig::get('IMAP_USERNAME'), EmailConfig::get('IMAP_PASSWORD'));
    
    if (!$inbox) {
        throw new Exception("Falha na conexão: " . imap_last_error());
    }
    
    log_msg("✅ Conectado com sucesso");
    
    // Buscar emails dos últimos 30 dias
    $data_limite = date('d-M-Y', strtotime('-30 days'));
    log_msg("Buscando emails desde: $data_limite");
    
    $emails = imap_search($inbox, "SINCE \"$data_limite\"", SE_UID);
    
    if (!$emails) {
        log_msg("📭 Nenhum email encontrado no período");
        imap_close($inbox);
        exit;
    }
    
    $total = count($emails);
    log_msg("📧 Total de emails no período: $total");
    
    $processados = 0;
    $nao_processados = 0;
    $erros = 0;
    
    foreach ($emails as $uid) {
        $msgno = imap_msgno($inbox, $uid);
        $header = imap_headerinfo($inbox, $msgno);
        
        $message_id = $header->message_id ?? null;
        $assunto = mb_decode_mimeheader($header->subject ?? 'Sem assunto');
        $remetente = $header->from[0]->mailbox . '@' . $header->from[0]->host;
        $data = date('d/m/Y H:i', strtotime($header->date));
        
        // Verificar se foi processado
        $query = "SELECT id, tipo FROM emails_processados WHERE message_id = :message_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':message_id', $message_id);
        $stmt->execute();
        $registro = $stmt->fetch();
        
        if ($registro) {
            $processados++;
            log_msg("✅ [$data] $remetente - $assunto (Processado como {$registro['tipo']})");
        } else {
            $nao_processados++;
            log_msg("⚠️ [$data] $remetente - $assunto (NÃO PROCESSADO)");
            
            // Marcar como não lido para reprocessar
            imap_clearflag_full($inbox, $uid, "\\Seen", ST_UID);
            log_msg("   → Marcado como não lido para reprocessamento");
        }
    }
    
    imap_close($inbox);
    
    log_msg("\n=== RESUMO ===");
    log_msg("Total analisados: $total");
    log_msg("✅ Processados: $processados");
    log_msg("⚠️ Não processados: $nao_processados");
    
    if ($nao_processados > 0) {
        log_msg("\n🔄 Execute o cron de verificação para processar os emails marcados");
    }
    
} catch (Exception $e) {
    log_msg("❌ ERRO: " . $e->getMessage());
}

log_msg("=== FIM DA VERIFICAÇÃO ===\n");
?>
