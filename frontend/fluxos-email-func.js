function mostrarFluxosEmail() {
    document.querySelectorAll('.btn-ajuda').forEach(btn => btn.classList.remove('active'));
    const _bbtn_fe = document.getElementById('btn-fluxos-email'); if (_bbtn_fe) _bbtn_fe.classList.add('active');
    localStorage.setItem('cd_subSecaoAtiva', 'fluxos-email');
    
    const content = document.getElementById('ajuda-content');
    content.innerHTML = `
        <div class="ajuda-container" style="animation: fadeInUp 0.4s ease-out;">
            <div style="background: linear-gradient(135deg, #5f5852 0%, #2b2523 100%); color: white; padding: 2rem; border-radius: 12px; margin-bottom: 2rem; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                <h3 style="margin: 0; font-size: 1.8rem; display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 2rem;">📧</span>
                    Lógica de Envio de E-mails do Sistema
                </h3>
                <p style="margin: 0.5rem 0 0 0; opacity: 0.95;">Entenda como funciona o sistema de notificações automáticas por email</p>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem;">
                <div class="email-card">
                    <div class="email-card-header" style="background: #5f5852;">
                        <span class="email-icon">🎫</span>
                        <h4>1. Nova Demanda Criada</h4>
                    </div>
                    <div class="email-card-body">
                        <div class="email-badge" style="background: #fbeceb; color: #a7241c; margin-bottom: 1rem;">📧 Envio 1 de 2</div>
                        <div class="email-item">
                            <div class="email-label">Para:</div>
                            <div class="email-value">Solicitante (usuário que criou)</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Assunto:</div>
                            <div class="email-value">🎫 [#CRPRS2026-0001] Demanda criada - [Título]</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Quando:</div>
                            <div class="email-value">Demanda criada via interface</div>
                        </div>
                        <div class="email-divider" style="background: linear-gradient(to right, #a7241c, transparent); height: 2px;"></div>
                        <div class="email-badge" style="background: #fbeceb; color: #a7241c; margin-bottom: 1rem;">📧 Envio 2 de 2</div>
                        <div class="email-item">
                            <div class="email-label">Para:</div>
                            <div class="email-value">Gestores do setor da demanda (ou administradores, se o setor não tiver gestor)</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Assunto:</div>
                            <div class="email-value">🎫 [NOVA DEMANDA] #CRPRS2026-0001 - [Título]</div>
                        </div>
                    </div>
                </div>
                
                <div class="email-card">
                    <div class="email-card-header" style="background: #5f5852;">
                        <span class="email-icon">💬</span>
                        <h4>2. Comentário Adicionado pela Equipe</h4>
                    </div>
                    <div class="email-card-body">
                        <div class="email-item">
                            <div class="email-label">Para:</div>
                            <div class="email-value">Solicitante da demanda</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Assunto:</div>
                            <div class="email-value">[#CRPRS2026-0001] Resposta do Suporte - [Título]</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Anexos:</div>
                            <div class="email-value">Inclui anexos do comentário (se houver)</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Quando:</div>
                            <div class="email-value">A equipe marca o checkbox "Enviar email" ao adicionar o comentário</div>
                        </div>
                    </div>
                </div>
                
                <div class="email-card">
                    <div class="email-card-header" style="background: #5f5852;">
                        <span class="email-icon">📨</span>
                        <h4>3. Comentário Recebido por Email</h4>
                    </div>
                    <div class="email-card-body">
                        <div class="email-item">
                            <div class="email-label">Para:</div>
                            <div class="email-value">
                                <div>• Se a demanda TEM responsável: apenas o responsável</div>
                                <div>• Se a demanda SEM responsável: os gestores do setor (ou administradores)</div>
                            </div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Assunto:</div>
                            <div class="email-value">🎫 [#CRPRS2026-0001] Novo comentário - [Título]</div>
                        </div>
                        <div class="email-divider" style="background: linear-gradient(to right, #a7241c, transparent); height: 2px;"></div>
                        <div class="email-badge" style="background: #fff3cd; color: #f57c00; margin-bottom: 1rem;">📧 Email adicional de confirmação</div>
                        <div class="email-item">
                            <div class="email-label">Para:</div>
                            <div class="email-value">Remetente do email</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Assunto:</div>
                            <div class="email-value">🎫 [#CRPRS2026-0001] Comentário recebido - [Título]</div>
                        </div>
                    </div>
                </div>
                
                <div class="email-card">
                    <div class="email-card-header" style="background: #5f5852;">
                        <span class="email-icon">👤</span>
                        <h4>4. Demanda Atribuída</h4>
                    </div>
                    <div class="email-card-body">
                        <div class="email-item">
                            <div class="email-label">Para:</div>
                            <div class="email-value">Responsável atribuído</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Assunto:</div>
                            <div class="email-value">👤 Demanda #CRPRS2026-0001 atribuída para você</div>
                        </div>
                        <div class="email-divider" style="background: linear-gradient(to right, #a7241c, transparent); height: 2px;"></div>
                        <div class="email-badge" style="background: #fff3cd; color: #f57c00; margin-bottom: 1rem;">⭐ Email adicional ao solicitante</div>
                        <div class="email-item">
                            <div class="email-label">Para:</div>
                            <div class="email-value">Solicitante</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Assunto:</div>
                            <div class="email-value">🎫 [#CRPRS2026-0001] Primeira resposta - [Título]</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Quando:</div>
                            <div class="email-value">Demanda SEM atribuição é atribuída pela primeira vez</div>
                        </div>
                        <div class="email-badge">⏰ Registra primeira resposta para cálculo de SLA</div>
                    </div>
                </div>
                
                <div class="email-card">
                    <div class="email-card-header" style="background: #5f5852;">
                        <span class="email-icon">✅</span>
                        <h4>5. Demanda Fechada</h4>
                    </div>
                    <div class="email-card-body">
                        <div class="email-item">
                            <div class="email-label">Para:</div>
                            <div class="email-value">Solicitante (se demanda criado por email)</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Assunto:</div>
                            <div class="email-value">✅ [#CRPRS2026-0001] Demanda fechada - [Título]</div>
                        </div>
                        <div class="email-divider" style="background: linear-gradient(to right, #a7241c, transparent); height: 2px;"></div>
                        <div class="email-badge" style="background: #ffebee; color: #c62828; margin-bottom: 1rem;">📧 Email adicional (condicional)</div>
                        <div class="email-item">
                            <div class="email-label">Para:</div>
                            <div class="email-value">Responsável (se outro usuário encerrou)</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Assunto:</div>
                            <div class="email-value">🔒 Demanda #CRPRS2026-0001 foi fechada</div>
                        </div>
                    </div>
                </div>
                
                <div class="email-card">
                    <div class="email-card-header" style="background: #5f5852;">
                        <span class="email-icon">🔄</span>
                        <h4>6. Demanda Reaberta</h4>
                    </div>
                    <div class="email-card-body">
                        <div class="email-item">
                            <div class="email-label">Para:</div>
                            <div class="email-value">Responsável</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Assunto:</div>
                            <div class="email-value">🔄 Demanda #CRPRS2026-0001 foi reaberta</div>
                        </div>
                        <div class="email-item">
                            <div class="email-label">Quando:</div>
                            <div class="email-value">
                                <div>• Email recebido com número de demanda fechada</div>
                                <div>• Demanda reaberta manualmente</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="email-section" style="background: #f7f4f0; padding: 1.5rem; border-radius: 12px; margin-top: 2rem; border-left: 4px solid #5f5852;">
                <h4 style="color: #495057; margin-top: 0; display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1.5rem;">📄</span>
                    Formatos de Email
                </h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="email-format-box" style="background: white; padding: 1rem; border-radius: 8px; border: 1px solid #ddd6cd;">
                        <div style="font-weight: 600; color: #495057; margin-bottom: 0.5rem;">👥 Equipe do setor (gestores e operadores)</div>
                        <div style="color: #5f5852; font-size: 0.9rem;">Recebem emails em <strong>texto simples</strong></div>
                    </div>
                    <div class="email-format-box" style="background: white; padding: 1rem; border-radius: 8px; border: 1px solid #ddd6cd;">
                        <div style="font-weight: 600; color: #495057; margin-bottom: 0.5rem;">👤 Solicitantes</div>
                        <div style="color: #5f5852; font-size: 0.9rem;">Recebem emails com <strong>templates HTML</strong></div>
                    </div>
                </div>
            </div>
            
            <div class="email-section" style="background: #f7f4f0; padding: 1.5rem; border-radius: 12px; margin-top: 1.5rem; border-left: 4px solid #17a2b8;">
                <h4 style="color: #495057; margin-top: 0; display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-size: 1.5rem;">📋</span>
                    Templates HTML Utilizados
                </h4>
                <div style="display: grid; gap: 0.75rem;">
                    <div class="template-box" style="background: white; padding: 1rem; border-radius: 8px; border-left: 3px solid #28a745;">
                        <div style="font-weight: 600; color: #495057; margin-bottom: 0.25rem;">email-template.html</div>
                        <div style="color: #5f5852; font-size: 0.85rem; margin-bottom: 0.25rem;">Confirmação de criação de demanda</div>
                        <div style="color: #868e96; font-size: 0.8rem; font-family: monospace;">📁 backend/email-template.html</div>
                    </div>
                    <div class="template-box" style="background: white; padding: 1rem; border-radius: 8px; border-left: 3px solid #a7241c;">
                        <div style="font-weight: 600; color: #495057; margin-bottom: 0.25rem;">email-template-resposta.html</div>
                        <div style="color: #5f5852; font-size: 0.85rem; margin-bottom: 0.25rem;">Resposta da equipe ao solicitante</div>
                        <div style="color: #868e96; font-size: 0.8rem; font-family: monospace;">📁 backend/email-template-resposta.html</div>
                    </div>
                    <div class="template-box" style="background: white; padding: 1rem; border-radius: 8px; border-left: 3px solid #ffc107;">
                        <div style="font-weight: 600; color: #495057; margin-bottom: 0.25rem;">email-template-comentario.html</div>
                        <div style="color: #5f5852; font-size: 0.85rem; margin-bottom: 0.25rem;">Confirmação de comentário recebido por email</div>
                        <div style="color: #868e96; font-size: 0.8rem; font-family: monospace;">📁 backend/email-template-comentario.html</div>
                    </div>
                    <div class="template-box" style="background: white; padding: 1rem; border-radius: 8px; border-left: 3px solid #8b1d17;">
                        <div style="font-weight: 600; color: #495057; margin-bottom: 0.25rem;">email-template-emcopia.html</div>
                        <div style="color: #5f5852; font-size: 0.85rem; margin-bottom: 0.25rem;">Cópia de resposta para terceiros</div>
                        <div style="color: #868e96; font-size: 0.8rem; font-family: monospace;">📁 backend/email-template-emcopia.html</div>
                    </div>
                    <div class="template-box" style="background: white; padding: 1rem; border-radius: 8px; border-left: 3px solid #dc3545;">
                        <div style="font-weight: 600; color: #495057; margin-bottom: 0.25rem;">email-template-fechamento.html</div>
                        <div style="color: #5f5852; font-size: 0.85rem; margin-bottom: 0.25rem;">Notificação de demanda fechada</div>
                        <div style="color: #868e96; font-size: 0.8rem; font-family: monospace;">📁 backend/email-template-fechamento.html</div>
                    </div>
                    <div class="template-box" style="background: white; padding: 1rem; border-radius: 8px; border-left: 3px solid #fd7e14;">
                        <div style="font-weight: 600; color: #495057; margin-bottom: 0.25rem;">email-template-primeira-resposta.html</div>
                        <div style="color: #5f5852; font-size: 0.85rem; margin-bottom: 0.25rem;">Primeira atribuição da demanda ao solicitante</div>
                        <div style="color: #868e96; font-size: 0.8rem; font-family: monospace;">📁 backend/email-template-primeira-resposta.html</div>
                    </div>
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 2rem;">
                <div class="info-box" style="background: linear-gradient(135deg, #fbeceb 0%, #f5d3d0 100%); border-left: 4px solid #a7241c;">
                    <h4 style="color: #a7241c; margin-top: 0; display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-size: 1.5rem;">🔔</span>
                        Indicadores Visuais
                    </h4>
                    <p><strong>Comentários não lidos:</strong> Ícone 💬 aparece na lista de demandas</p>
                    <div class="info-inner-box" style="margin-top: 1rem; padding: 0.75rem; background: white; border-radius: 6px;">
                        <div style="margin-bottom: 0.5rem;">• Demanda COM responsável → apenas ele vê</div>
                        <div style="margin-bottom: 0.5rem;">• Demanda SEM responsável → os gestores do setor veem</div>
                        <div>• Remove ao visualizar detalhes</div>
                    </div>
                </div>
                
                <div class="info-box" style="background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%); border-left: 4px solid #ffc107;">
                    <h4 style="color: #f57c00; margin-top: 0; display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-size: 1.5rem;">⚙️</span>
                        Configuração SMTP
                    </h4>
                    <div class="info-inner-box" style="margin-top: 1rem; padding: 0.75rem; background: white; border-radius: 6px;">
                        <div style="margin-bottom: 0.5rem;"><strong>Servidor:</strong> email-ssl.com.br:465 (SSL)</div>
                        <div style="margin-bottom: 0.5rem;"><strong>Charset:</strong> UTF-8</div>
                        <div style="margin-bottom: 0.5rem;"><strong>Timeout:</strong> 10 segundos</div>
                        <div style="color: #666; font-size: 0.9rem; margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #eee;">
                            🔧 Acesse Configurações → Configurar Email (apenas admins)
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
            .email-card {
                background: white;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                transition: transform 0.2s, box-shadow 0.2s;
            }
            .email-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            }
            .email-card-header {
                color: white;
                padding: 1.25rem;
                display: flex;
                align-items: center;
                gap: 0.75rem;
            }
            .email-card-header h4 {
                margin: 0;
                font-size: 1.1rem;
                font-weight: 600;
            }
            .email-icon {
                font-size: 1.5rem;
            }
            .email-card-body {
                padding: 1.5rem;
            }
            .email-item {
                display: grid;
                grid-template-columns: 120px 1fr;
                gap: 1rem;
                margin-bottom: 1rem;
                align-items: start;
            }
            .email-label {
                font-weight: 600;
                color: #555;
                font-size: 0.9rem;
            }
            .email-value {
                color: #333;
                line-height: 1.6;
            }
            .email-divider {
                height: 1px;
                background: linear-gradient(to right, #e0e0e0, transparent);
                margin: 1rem 0;
            }
            .email-subtitle {
                font-weight: 600;
                color: #666;
                margin: 1rem 0 0.75rem 0;
                font-size: 0.95rem;
            }
            .email-badge {
                display: inline-block;
                background: #e8f5e9;
                color: #2e7d32;
                padding: 0.5rem 1rem;
                border-radius: 20px;
                font-size: 0.85rem;
                font-weight: 600;
                margin-top: 0.5rem;
            }
            .info-box {
                padding: 1.5rem;
                border-radius: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            }
            
            .modo-escuro .email-card {
                background: #2d2d2d;
            }
            .modo-escuro .email-card-body {
                color: #e0e0e0;
            }
            .modo-escuro .email-label {
                color: #b0b0b0;
            }
            .modo-escuro .email-value {
                color: #e0e0e0;
            }
            .modo-escuro .info-box {
                background: #2d2d2d !important;
            }
            .modo-escuro .info-box h4 {
                color: var(--laranja) !important;
            }
            .modo-escuro .info-box p,
            .modo-escuro .info-box div {
                color: #e0e0e0 !important;
            }
            .modo-escuro .email-section {
                background: #2d2d2d !important;
            }
            .modo-escuro .email-section h4 {
                color: var(--laranja) !important;
            }
            .modo-escuro .email-format-box,
            .modo-escuro .template-box {
                background: #3d3d3d !important;
                border-color: #555 !important;
            }
            .modo-escuro .email-format-box div,
            .modo-escuro .template-box div {
                color: #e0e0e0 !important;
            }
            .modo-escuro .info-inner-box {
                background: #3d3d3d !important;
                border-color: #555 !important;
            }
            .modo-escuro .info-inner-box div {
                color: #e0e0e0 !important;
            }
        </style>
    `;
}
