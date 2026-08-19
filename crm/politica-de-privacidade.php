<?php
declare(strict_types=1);

$updatedAt = '8 de julho de 2026';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index,follow">
    <title>Política de Privacidade | Publi AI Soluções</title>
    <meta
        name="description"
        content="Política de Privacidade da Publi AI Soluções para o CRM e as integrações com WhatsApp e Meta."
    >
    <style>
        :root {
            color-scheme: light;
            --background: #f6f7f9;
            --surface: #ffffff;
            --text: #182230;
            --muted: #5d6878;
            --border: #d9dee7;
            --accent: #087f5b;
            --accent-dark: #066849;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 16px;
            line-height: 1.65;
        }

        header {
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }

        .header-inner,
        main,
        footer {
            width: min(100% - 32px, 860px);
            margin-inline: auto;
        }

        .header-inner {
            display: flex;
            align-items: center;
            min-height: 72px;
            font-weight: 800;
        }

        main {
            padding-block: 56px 72px;
        }

        h1,
        h2 {
            line-height: 1.25;
        }

        h1 {
            margin: 0 0 12px;
            font-size: clamp(2rem, 6vw, 3rem);
        }

        h2 {
            margin: 40px 0 12px;
            font-size: 1.35rem;
        }

        p,
        ul {
            margin: 0 0 16px;
        }

        ul {
            padding-left: 24px;
        }

        .updated {
            color: var(--muted);
        }

        a {
            color: var(--accent-dark);
        }

        .contact-link {
            display: inline-flex;
            align-items: center;
            min-height: 44px;
            margin-top: 8px;
            padding: 9px 16px;
            border: 1px solid var(--accent);
            border-radius: 6px;
            color: var(--accent-dark);
            font-weight: 700;
            text-decoration: none;
        }

        .contact-link:hover,
        .contact-link:focus-visible {
            background: #e9f7f1;
        }

        footer {
            padding-block: 24px 40px;
            border-top: 1px solid var(--border);
            color: var(--muted);
        }
    </style>
</head>
<body>
    <header>
        <div class="header-inner">Publi AI Soluções</div>
    </header>

    <main>
        <h1>Política de Privacidade</h1>
        <p class="updated">Última atualização: <?= htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8') ?></p>

        <p>
            Esta Política de Privacidade explica como a Publi AI Soluções trata dados pessoais
            por meio de seu CRM, de seus formulários e de integrações com a Plataforma do
            WhatsApp Business e outros produtos da Meta.
        </p>

        <h2>1. Dados que podemos tratar</h2>
        <p>De acordo com o uso dos nossos serviços, podemos tratar:</p>
        <ul>
            <li>nome, telefone, empresa e demais dados informados em formulários;</li>
            <li>identificadores de contas e números do WhatsApp Business;</li>
            <li>mensagens, anexos e informações de contato enviados ou recebidos pelo WhatsApp;</li>
            <li>status de entrega, leitura, falha e outros eventos relacionados às mensagens;</li>
            <li>registros técnicos, como data, horário, endereço IP e logs de segurança;</li>
            <li>dados de autenticação e autorização necessários para operar as integrações.</li>
        </ul>

        <h2>2. Como usamos os dados</h2>
        <p>Os dados podem ser utilizados para:</p>
        <ul>
            <li>captar e organizar contatos e oportunidades comerciais;</li>
            <li>enviar, receber e acompanhar mensagens autorizadas pelo WhatsApp;</li>
            <li>executar automações e notificações configuradas pelo cliente;</li>
            <li>prestar suporte, prevenir fraudes e manter a segurança dos serviços;</li>
            <li>cumprir obrigações legais e exercer direitos em processos administrativos ou judiciais.</li>
        </ul>

        <h2>3. Bases legais</h2>
        <p>
            O tratamento ocorre conforme a legislação aplicável, incluindo a Lei Geral de
            Proteção de Dados Pessoais (LGPD), com base na execução de contrato, no
            cumprimento de obrigação legal, no legítimo interesse ou no consentimento,
            quando necessário.
        </p>

        <h2>4. Compartilhamento de dados</h2>
        <p>
            Podemos compartilhar dados com a Meta e o WhatsApp, provedores de hospedagem,
            infraestrutura, segurança e outros fornecedores essenciais para a prestação dos
            serviços. Esses fornecedores recebem somente os dados necessários para executar
            suas atividades. Não vendemos dados pessoais.
        </p>

        <h2>5. Transferências internacionais</h2>
        <p>
            Alguns fornecedores podem processar dados fora do Brasil. Nesses casos, adotamos
            medidas compatíveis com a legislação aplicável e com os mecanismos de proteção
            oferecidos pelos respectivos provedores.
        </p>

        <h2>6. Armazenamento e segurança</h2>
        <p>
            Mantemos os dados pelo período necessário para prestar os serviços, atender às
            finalidades descritas nesta política e cumprir obrigações legais. Aplicamos
            controles técnicos e organizacionais destinados a proteger os dados contra
            acesso, alteração, divulgação ou destruição não autorizados.
        </p>

        <h2>7. Direitos do titular</h2>
        <p>
            O titular pode solicitar confirmação do tratamento, acesso, correção,
            portabilidade, informações sobre compartilhamento, revogação do consentimento,
            oposição ou eliminação de dados, quando aplicável.
        </p>

        <h2 id="exclusao">8. Exclusão de dados</h2>
        <p>
            Para solicitar a exclusão de dados relacionados ao CRM ou à integração com o
            WhatsApp, entre em contato informando seu nome, número de telefone e a empresa
            relacionada. A identidade do solicitante poderá ser confirmada antes do
            atendimento. Dados sujeitos a obrigações legais poderão ser mantidos pelo prazo
            exigido pela legislação.
        </p>
        <a
            class="contact-link"
            href="https://wa.me/554288187793?text=Quero%20solicitar%20acesso%20ou%20exclusao%20dos%20meus%20dados"
            target="_blank"
            rel="noopener noreferrer"
        >
            Solicitar pelo WhatsApp
        </a>

        <h2>9. Responsabilidades dos clientes</h2>
        <p>
            Empresas que utilizam o CRM devem possuir uma base legal adequada para tratar os
            dados de seus contatos e respeitar as políticas do WhatsApp e da Meta, inclusive
            as regras de consentimento, modelos de mensagem e comunicações de marketing.
        </p>

        <h2>10. Atualizações desta política</h2>
        <p>
            Esta política pode ser atualizada para refletir alterações nos serviços ou na
            legislação. A versão vigente será publicada nesta página com a data da última
            atualização.
        </p>

        <h2>11. Contato</h2>
        <p>
            Dúvidas ou solicitações sobre privacidade podem ser encaminhadas pelo WhatsApp
            oficial da Publi AI Soluções: +55 42 8818-7793.
        </p>
    </main>

    <footer>Publi AI Soluções</footer>
</body>
</html>
