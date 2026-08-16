function testarConexaoEmail() {
    const data = {
        imap_host: document.getElementById('imap_host').value,
        imap_port: document.getElementById('imap_port').value,
        imap_username: document.getElementById('imap_username').value,
        imap_password: document.getElementById('imap_password').value,
        smtp_host: document.getElementById('smtp_host').value,
        smtp_port: document.getElementById('smtp_port').value,
        smtp_username: document.getElementById('smtp_username').value,
        smtp_password: document.getElementById('smtp_password').value
    };
    
    mostrarLoader();
    
    const xhr = new XMLHttpRequest();
    xhr.open('POST', window.APP_CONFIG.apiBase + 'testar-email.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    setSecurityHeaders(xhr);
    
    xhr.onload = function() {
        ocultarLoader();
        if (xhr.status === 200) {
            const resultado = JSON.parse(xhr.responseText);
            
            let mensagem = '<div style="text-align: left;">';
            
            if (resultado.imap.sucesso) {
                mensagem += '<p style="color: #28a745;">✅ <strong>IMAP:</strong> ' + resultado.imap.mensagem + '</p>';
            } else {
                mensagem += '<p style="color: #dc3545;">❌ <strong>IMAP:</strong> ' + resultado.imap.mensagem + '</p>';
            }
            
            if (resultado.smtp.sucesso) {
                mensagem += '<p style="color: #28a745;">✅ <strong>SMTP:</strong> ' + resultado.smtp.mensagem + '</p>';
            } else {
                mensagem += '<p style="color: #dc3545;">❌ <strong>SMTP:</strong> ' + resultado.smtp.mensagem + '</p>';
            }
            
            mensagem += '</div>';
            
            const titulo = (resultado.imap.sucesso && resultado.smtp.sucesso) ? 'Teste Bem-Sucedido' : 'Teste com Falhas';
            mostrarAlert(titulo, mensagem);
        } else {
            mostrarAlert('Erro', 'Erro ao testar conexão');
        }
    };
    
    xhr.onerror = function() {
        ocultarLoader();
        mostrarAlert('Erro', 'Erro de conexão ao testar');
    };
    
    xhr.send(JSON.stringify(data));
}
