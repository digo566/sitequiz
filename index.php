<?php
// index.php - Arquivo ÚNICO (Quiz Frontend + Admin Backend)

// **********************************************
// 1. CONFIGURAÇÕES E FUNÇÕES BASE
// **********************************************

// Ativar exibição de erros (COMENTE EM PRODUÇÃO)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

session_start();

// CREDENCIAIS DE CONEXÃO
function pdo(): PDO {
    // ⚠️ SUAS CREDENCIAIS
    $host = 'localhost'; 
    $db_name = 'u867939796_bdsysup1'; 
    $user = 'u867939796_root'; 
    $password = '089Digo089'; 

    try {
        $dsn = "mysql:host={$host};dbname={$db_name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        error_log("ERRO FATAL DE CONEXÃO PDO: " . $e->getMessage());
        if (isset($_GET['action'])) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Erro interno ao conectar ao BD.']);
            exit;
        }
        // Em caso de falha de BD, mostra o quiz, mas a função de salvar não irá funcionar.
        return null; 
    }
}

// Senha de Admin
$ADMIN_PASSWORD = '089Digo089'; 

function is_logged_in(): bool {
    return isset($_SESSION['auth']) && $_SESSION['auth'] === true;
}

// **********************************************
// 2. LÓGICA DE ROTEAMENTO (API & UTILS)
// **********************************************

// Lógica de Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

// === ROTAS DA API/UTILS CHAMADAS VIA AJAX/LINK ===
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    header('Content-Type: application/json; charset=utf-8');
    $pdo = pdo();
    
    // Se a conexão PDO falhou, retorna erro
    if ($pdo === null) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Conexão com o banco de dados falhou.']);
        exit;
    }

    // --- Rota de Salvar Contato Inicial (save_start.php)
    if ($action === 'save_start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $nome = $_POST['nome'] ?? '';
            $email = $_POST['email'] ?? '';
            $telefone = $_POST['telefone'] ?? '';

            if (empty($nome) || empty($email) || empty($telefone)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Campos de contato são obrigatórios.']);
                exit;
            }
            
            $session_id = session_id();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            // Salva ou atualiza os dados de contato, mantendo a session_id como chave
            $stmt = $pdo->prepare("
                INSERT INTO leads (session_id, nome, email, telefone, ip, status, created_at) 
                VALUES (?, ?, ?, ?, ?, 'lead', NOW())
                ON DUPLICATE KEY UPDATE 
                    nome = VALUES(nome), email = VALUES(email), telefone = VALUES(telefone), ip = VALUES(ip), updated_at = NOW()
            ");
            $stmt->execute([$session_id, $nome, $email, $telefone, $ip]);
            
            echo json_encode(['ok' => true, 'message' => 'Lead iniciado.']);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Erro save_start: " . $e->getMessage());
            echo json_encode(['ok' => false, 'message' => 'Erro interno ao salvar.']);
        }
        exit;
    }

    // --- Rota de Salvar Respostas Finais (save_lead.php)
    if ($action === 'save_final' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $session_id = session_id();
            $ramo = $_POST['ramo'] ?? null;
            $usa_ferramenta = $_POST['usa_ferramenta'] ?? null;
            $desafio = $_POST['desafio'] ?? null;

            if (empty($ramo) || empty($desafio)) { // Simplificando a validação para as perguntas chave
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'As respostas do quiz são obrigatórias.']);
                exit;
            }

            // Atualiza o lead com as respostas do quiz e muda o status para 'completo'
            $stmt = $pdo->prepare("
                UPDATE leads 
                SET ramo = ?, usa_ferramenta = ?, desafio = ?, status = 'completo', updated_at = NOW() 
                WHERE session_id = ?
            ");
            $stmt->execute([$ramo, $usa_ferramenta, $desafio, $session_id]);

            if ($stmt->rowCount() === 0) {
                // Isso pode acontecer se o usuário pular o primeiro passo. 
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'Sessão de lead não encontrada para finalizar.']);
                exit;
            }
            
            echo json_encode(['ok' => true, 'message' => 'Respostas salvas.']);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Erro save_final: " . $e->getMessage());
            echo json_encode(['ok' => false, 'message' => 'Erro interno ao salvar.']);
        }
        exit;
    }

    // --- Rota de Exclusão (delete_leads.php)
    if ($action === 'delete_leads' && is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            $ids = $data['ids'] ?? [];
            if (!is_array($ids) || count($ids) === 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Nenhum lead selecionado.']);
                exit;
            }

            $safe_ids = array_filter($ids, 'is_numeric');
            $placeholders = implode(',', array_fill(0, count($safe_ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM leads WHERE id IN ({$placeholders})");
            $stmt->execute($safe_ids);

            $count = $stmt->rowCount();
            echo json_encode(['ok' => true, 'message' => "{$count} lead(s) excluído(s) com sucesso."]);

        } catch (Exception $e) {
            http_response_code(500);
            error_log("Erro ao deletar: " . $e->getMessage());
            echo json_encode(['ok' => false, 'message' => 'Erro no servidor.']);
        }
        exit;
    }

    // --- Rota de Download CSV (download.php)
    if ($action === 'download_csv' && is_logged_in()) {
        // Redireciona o download, headers fora do JSON
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leads_quiz_' . date('Ymd_His') . '.csv"');
        
        try {
            $stmt = $pdo->query("SELECT id, created_at, ip, nome, email, telefone, ramo, usa_ferramenta, desafio, status FROM leads ORDER BY created_at DESC");
            $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $output = fopen('php://output', 'w');
            fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM para UTF-8

            $translated_header = ['id' => 'ID', 'created_at' => 'Data Cadastro', 'ip' => 'IP', 'nome' => 'Nome', 'email' => 'Email', 'telefone' => 'Telefone', 'ramo' => 'Ramo de Atuação', 'usa_ferramenta' => 'Usa Ferramenta', 'desafio' => 'Desafio Principal', 'status' => 'Status'];
            fputcsv($output, array_values($translated_header), ';');

            foreach ($leads as $lead) {
                $lead['created_at'] = (new DateTime($lead['created_at']))->format('d/m/Y H:i:s');
                fputcsv($output, $lead, ';');
            }

            fclose($output);
            exit;

        } catch (Exception $e) {
            error_log("Erro CSV: " . $e->getMessage());
            die("Erro ao gerar o arquivo de exportação.");
        }
    }
}

// **********************************************
// 3. LÓGICA DO PAINEL DE ADMIN (Se o usuário está logado)
// **********************************************

$rows = [];
if (isset($_GET['admin']) || is_logged_in()) {
    // Tenta fazer login
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if (hash_equals($ADMIN_PASSWORD, $_POST['password'])) {
            $_SESSION['auth'] = true;
            header('Location: index.php?admin'); // Redireciona para evitar reenvio de formulário
            exit;
        } else {
            $error = 'Senha incorreta';
        }
    }

    if (is_logged_in()) {
        try {
            $pdo = pdo();
            if ($pdo !== null) {
                // Seleciona todos os leads para exibição no painel
                $stmt = $pdo->query("SELECT id, created_at, ip, nome, email, telefone, ramo, usa_ferramenta, desafio, status FROM leads ORDER BY created_at DESC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            $error = "Erro ao carregar leads: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SysUp - Qual o Sistema Ideal para Você?</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
          theme: {
            extend: {
              colors: {
                // Cores corporativas: Roxo (Primary) e Verde (Accent/WhatsApp)
                'primary': '#5B21B6', // Roxo escuro
                'primary-light': '#8B5CF6',
                'dark-text': '#1f2937',
                'accent': '#25d366', // Verde WhatsApp
              },
            }
          }
        }
    </script>
    <style>
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f3f4f6; /* Fundo cinza claro para o layout */
        }
        .card { 
            background: #ffffff; 
            border-radius: 12px; 
            box-shadow: 0 15px 30px -5px rgba(0, 0, 0, 0.1); 
            transition: all 0.3s ease;
        }
        .step-container {
            transition: opacity 0.4s ease-in-out, transform 0.4s ease-in-out;
        }
        .hidden-step {
            opacity: 0;
            transform: translateY(20px);
            display: none;
        }
        .active-step {
            opacity: 1;
            transform: translateY(0);
            display: block;
        }
        .radio-card {
            cursor: pointer;
            border: 2px solid #e5e7eb;
            transition: all 0.2s;
        }
        .radio-card:hover {
            border-color: #A78BFA;
            background-color: #F5F3FF;
        }
        input[type="radio"]:checked + .radio-card {
            border-color: #5B21B6;
            background-color: #EDE9FE;
            box-shadow: 0 0 0 4px #DDD6FE;
        }
        .modal-overlay {
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 50;
        }
        .audio-player {
            width: 100%;
            margin: 10px 0;
            border-radius: 8px;
        }
    </style>
</head>
<body class="p-4 sm:p-8 min-h-screen">
    
    <!-- Modal Customizado (Alerta/Confirmação) -->
    <div id="custom-modal" class="modal-overlay fixed inset-0 hidden items-center justify-center">
        <div class="modal-content card p-6 w-full max-w-sm mx-4 bg-white">
          <h3 id="modal-title" class="text-lg font-bold text-dark-text mb-3"></h3>
          <p id="modal-message" class="text-gray-600 mb-6"></p>
          <div id="modal-actions" class="flex justify-end space-x-3"></div>
        </div>
    </div>

    <!-- CONTEÚDO PRINCIPAL -->
    <div class="max-w-7xl mx-auto shadow-xl p-4 sm:p-6 md:p-8">
        <header class="flex justify-between items-center mb-6">
            <h1 class="text-2xl sm:text-3xl font-bold text-dark-text">
                     
            </h1>
            <div class="flex items-center space-x-4">
                <?php if (is_logged_in()): ?>
                    <a href="index.php" class="text-sm font-medium text-gray-600 hover:text-primary-light">Ver Quiz</a>
                    <a href="?logout" class="text-sm font-medium text-red-600 hover:text-red-800">Sair</a>
                <?php else: ?>
                    <a href="?admin" class="text-sm font-medium text-primary hover:text-primary-light">Acesso Restrito</a>
                <?php endif; ?>
            </div>
        </header>

        <?php if (isset($error)): ?>
          <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-xl relative mb-6" role="alert">
            <strong class="font-bold">Erro:</strong>
            <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
          </div>
        <?php endif; ?>


        <?php if (isset($_GET['admin']) && !is_logged_in()): ?>
        <!-- ########################################## -->
        <!-- 4. UI DE LOGIN DE ADMIN -->
        <!-- ########################################## -->
        <div class="card p-8 max-w-sm mx-auto mt-12">
            <h2 class="text-2xl font-semibold mb-6 text-center text-dark-text">Acesso Restrito</h2>
            <form method="POST" action="index.php?admin">
              <input type="password" name="password" placeholder="Senha de Administrador" required 
                     class="w-full p-3 border border-gray-300 rounded-lg focus:ring-primary focus:border-primary mb-4">
              <button type="submit" class="bg-primary text-white font-semibold py-3 px-4 rounded-lg w-full hover:bg-primary-light transition duration-300">
                Entrar
              </button>
            </form>
          </div>

        <?php elseif (is_logged_in()): ?>
        <!-- ########################################## -->
        <!-- 5. UI DO PAINEL DE LEADS (Dados de Leads Completos) -->
        <!-- ########################################## -->
        <h2 class="text-3xl font-semibold text-dark-text mb-6">Dashboard de Leads</h2>
          <div class="card p-4 sm:p-6 overflow-x-auto">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 space-y-3 sm:space-y-0">
                <p class="text-gray-600 font-medium">Total de Leads: <?php echo count($rows); ?></p>
                <div class="flex space-x-3">
                  <button type="button" id="btn-delete" class="bg-red-500 text-white text-sm font-semibold py-2 px-4 rounded-lg hover:bg-red-600 transition duration-300 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 mr-1"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg> Excluir Selecionados
                  </button>
                  <a href="index.php?action=download_csv" class="bg-accent text-white text-sm font-semibold py-2 px-4 rounded-lg hover:opacity-90 transition duration-300 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 mr-1"><path d="M12 2v10"/><path d="m16 8-4 4-4-4"/><path d="M8 12v2c0 1.1.9 2 2 2h4c1.1 0 2-.9 2-2v-2"/><path d="M3 18h18"/></svg> Exportar CSV
                  </a>
                </div>
            </div>

            <form id="leads-form">
              <table class="min-w-full divide-y divide-gray-200">
                <thead>
                  <tr class="bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    <th class="p-3">
                      <input type="checkbox" id="check-all" class="rounded text-primary focus:ring-primary">
                    </th>
                    <th class="p-3">Data</th>
                    <th class="p-3">Nome</th>
                    <th class="p-3">Email</th>
                    <th class="p-3">Telefone</th>
                    <th class="p-3">Ramo</th>
                    <th class="p-3">Ferramenta</th>
                    <th class="p-3">Desafio</th>
                    <th class="p-3">Status</th>
                  </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200 text-sm text-gray-700">
                  <?php if (count($rows) === 0): ?>
                    <tr>
                      <td colspan="9" class="p-3 text-center text-gray-500">Nenhum lead encontrado.</td>
                    </tr>
                  <?php else: foreach ($rows as $r): ?>
                    <tr>
                      <td class="p-3">
                        <input type="checkbox" name="ids[]" value="<?php echo htmlspecialchars($r['id']); ?>" class="rounded text-primary focus:ring-primary">
                      </td>
                      <td class="p-3"><?php echo (new DateTime($r['created_at']))->format('d/m/Y H:i:s'); ?></td>
                      <td class="p-3"><?php echo htmlspecialchars($r['nome']); ?></td>
                      <td class="p-3"><?php echo htmlspecialchars($r['email']); ?></td>
                      <td class="p-3"><?php echo htmlspecialchars($r['telefone']); ?></td>
                      <td class="p-3"><?php echo htmlspecialchars($r['ramo'] ?? '-'); ?></td>
                      <td class="p-3"><?php echo htmlspecialchars($r['usa_ferramenta'] ?? '-'); ?></td>
                      <td class="p-3 max-w-xs truncate" title="<?php echo htmlspecialchars($r['desafio'] ?? '-'); ?>"><?php echo htmlspecialchars($r['desafio'] ?? '-'); ?></td>
                      <td class="p-3">
                        <?php if ($r['status'] === 'completo'): ?>
                          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">completo</span>
                        <?php else: ?>
                          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">lead</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </form>
          </div>

        <?php else: ?>
        <!-- ########################################## -->
        <!-- 6. UI DO QUIZ/FRONTEND -->
        <!-- ########################################## -->
        <div class="card p-6 sm:p-10 max-w-lg mx-auto mt-6">
            <h2 id="quiz-title" class="text-2xl sm:text-3xl font-extrabold mb-4 text-center text-dark-text">
                Descubra o Sistema Ideal para Seu Negócio
            </h2>
            <p id="quiz-subtitle" class="text-gray-600 text-center mb-8">Responda 3 perguntas rápidas para uma recomendação personalizada.</p>
            
            <div id="quiz-steps" class="relative">
                
                <!-- Etapa 1: Contato -->
                <div id="step-1" class="step-container active-step">
                    <h3 class="text-xl font-semibold mb-6 text-center text-primary">1. Seus Dados de Contato</h3>
                    
                    <!-- ÁUDIO 1 - BOAS VINDAS -->
                    <div class="mb-6 text-center">
                        <p class="text-sm text-gray-500 mb-2">Ouça nossa mensagem de boas-vindas:</p>
                        <audio controls class="audio-player">
                            <source src="audio1.mp3" type="audio/mpeg">
                            Seu navegador não suporta o elemento de áudio.
                        </audio>
                    </div>
                    
                    <form id="form-contato" class="space-y-5">
                      <input type="text" name="nome" placeholder="Seu Nome Completo" required class="w-full p-3 border border-gray-300 rounded-lg focus:ring-primary-light focus:border-primary-light transition">
                      <input type="email" name="email" placeholder="Seu Email Principal" required class="w-full p-3 border border-gray-300 rounded-lg focus:ring-primary-light focus:border-primary-light transition">
                      <input type="tel" name="telefone" placeholder="Seu Telefone (WhatsApp)" required class="w-full p-3 border border-gray-300 rounded-lg focus:ring-primary-light focus:border-primary-light transition">
                      <button type="submit" class="bg-primary text-white font-bold py-3 px-4 rounded-lg w-full hover:bg-primary-light transition duration-300">
                        Próxima Pergunta
                      </button>
                    </form>
                </div>

                <!-- Etapa 2: Ramo de Atuação -->
                <div id="step-2" class="step-container hidden-step">
                    <h3 class="text-xl font-semibold mb-6 text-center text-primary">2. Qual o seu principal Ramo de Atuação?</h3>
                    
                    <!-- ÁUDIO 2 - SOBRE MEU TRABALHO -->
                    <div class="mb-6 text-center">
                        <p class="text-sm text-gray-500 mb-2">Saiba mais sobre nosso trabalho:</p>
                        <audio controls class="audio-player">
                            <source src="audio2.mp3" type="audio/mpeg">
                            Seu navegador não suporta o elemento de áudio.
                        </audio>
                    </div>
                    
                    <form id="form-quiz-ramo" class="space-y-4">
                        <input type="radio" id="ramo-marketing" name="ramo" value="Marketing" class="hidden" required>
                        <label for="ramo-marketing" class="radio-card block p-4 rounded-lg">
                            <span class="font-semibold text-dark-text">Marketing Digital/Agência</span>
                        </label>

                        <input type="radio" id="ramo-servicos" name="ramo" value="Serviços" class="hidden">
                        <label for="ramo-servicos" class="radio-card block p-4 rounded-lg">
                            <span class="font-semibold text-dark-text">Prestação de Serviços (Consultoria, Cursos, etc.)</span>
                        </label>
                        
                        <input type="radio" id="ramo-comercio" name="ramo" value="Comércio" class="hidden">
                        <label for="ramo-comercio" class="radio-card block p-4 rounded-lg">
                            <span class="font-semibold text-dark-text">Comércio/Varejo (Loja Física, E-commerce)</span>
                        </label>

                        <input type="radio" id="ramo-saas" name="ramo" value="SAAS" class="hidden">
                        <label for="ramo-saas" class="radio-card block p-4 rounded-lg">
                            <span class="font-semibold text-dark-text">SaaS / Tecnologia (Software as a Service)</span>
                        </label>

                        <input type="radio" id="ramo-outros" name="ramo" value="Outros" class="hidden">
                        <label for="ramo-outros" class="radio-card block p-4 rounded-lg">
                            <span class="font-semibold text-dark-text">Outros / Não Listados</span>
                        </label>

                        <button type="submit" class="bg-primary text-white font-bold py-3 px-4 rounded-lg w-full hover:bg-primary-light transition duration-300 mt-6">
                            Próxima Pergunta
                        </button>
                    </form>
                </div>

                <!-- Etapa 3: Desafios -->
                <div id="step-3" class="step-container hidden-step">
                    <h3 class="text-xl font-semibold mb-6 text-center text-primary">3. Qual o seu maior Desafio Atual?</h3>
                    <form id="form-quiz-final" class="space-y-4">
                        <input type="radio" id="desafio-gestao" name="desafio" value="Gestão Financeira/Estoque" class="hidden" required>
                        <label for="desafio-gestao" class="radio-card block p-4 rounded-lg">
                            <span class="font-semibold text-dark-text">Gestão Financeira e Controle de Estoque</span>
                        </label>

                        <input type="radio" id="desafio-vendas" name="desafio" value="Organização de Vendas/Clientes" class="hidden">
                        <label for="desafio-vendas" class="radio-card block p-4 rounded-lg">
                            <span class="font-semibold text-dark-text">Organização de Vendas e Relacionamento com Clientes</span>
                        </label>
                        
                        <input type="radio" id="desafio-presenca" name="desafio" value="Presença Digital/Site" class="hidden">
                        <label for="desafio-presenca" class="radio-card block p-4 rounded-lg">
                            <span class="font-semibold text-dark-text">Ter um Site/Presença Digital Profissional</span>
                        </label>

                        <input type="radio" id="desafio-outros" name="desafio" value="Outros Desafios" class="hidden">
                        <label for="desafio-outros" class="radio-card block p-4 rounded-lg">
                            <span class="font-semibold text-dark-text">Outros Desafios Específicos</span>
                        </label>

                        <input type="hidden" name="usa_ferramenta" value="Não informado"> <!-- Campo usa_ferramenta (simplificado) -->
                        
                        <button type="submit" class="bg-accent text-white font-bold py-3 px-4 rounded-lg w-full hover:opacity-90 transition duration-300 mt-6 flex items-center justify-center space-x-2">
                            <span>Descobrir Meu Sistema Ideal</span>
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8l4 4-4 4"/><path d="M3 12h18"/></svg>
                        </button>
                    </form>
                </div>

                <!-- Etapa 4: Resultado -->
                <div id="step-4" class="step-container hidden-step">
                    <div class="text-center p-6 bg-green-50 rounded-lg">
                        <h3 class="text-2xl font-bold text-accent mb-4">Sua Recomendação Está Pronta!</h3>
                        <p class="text-dark-text mb-6">Com base nas suas respostas, o sistema ideal para otimizar seu negócio é o(a):</p>
                        <div id="resultado-sistema" class="text-4xl font-extrabold text-primary mb-8 bg-primary-light/10 p-4 rounded-lg"></div>

                        <!-- ÁUDIO 3 - SOBRE INVESTIMENTO -->
                        <div class="mb-6 text-center">
                            <p class="text-sm text-gray-500 mb-2">Informações sobre investimento:</p>
                            <audio controls class="audio-player">
                                <source src="audio3.mp3" type="audio/mpeg">
                                Seu navegador não suporta o elemento de áudio.
                            </audio>
                        </div>

                        <p class="text-gray-600 mb-6">Clique no botão abaixo para falar diretamente com um especialista e receber uma proposta personalizada.</p>
                        
                        <a id="whatsapp-link" href="#" target="_blank" class="inline-flex items-center justify-center space-x-2 bg-accent text-white font-bold py-3 px-6 rounded-full hover:bg-green-500 transition duration-300 shadow-xl shadow-green-500/50">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12.001 2.002c-5.522 0-9.999 4.477-9.999 9.999 0 1.777.46 3.454 1.258 4.939l-1.314 4.793 4.887-1.288c1.42.715 3.033 1.096 4.674 1.096h.001c5.522 0 9.999-4.477 9.999-9.999s-4.477-9.999-9.999-9.999zm4.783 14.801c-.247.382-.572.365-.828.318-.2-.04-.847-.31-.994-.366-.145-.054-.251-.081-.357.081-.106.162-.413.518-.504.624-.09.106-.184.12-.338.037-.626-.295-1.121-.433-2.316-1.423-1.464-1.248-2.454-2.812-2.73-3.298-.276-.486-.032-.746.195-.972.196-.195.432-.518.577-.692.146-.174.195-.295.291-.491.096-.196.048-.367-.024-.518-.073-.151-.663-1.597-.909-2.188-.236-.583-.474-.498-.65-.512-.178-.014-.383-.004-.589-.004-.207 0-.539.078-.813.388-.274.308-1.047 1.022-1.047 2.482 0 1.46 1.074 2.87 1.229 3.086.155.216 2.094 3.2 5.094 4.407 2.992 1.202 3.829.897 4.518.839.69-.059 1.871-.762 2.138-1.52.269-.757.269-1.393.187-1.52-.083-.127-.247-.196-.518-.323z"/></svg>
                            <span>Falar no WhatsApp Agora!</span>
                        </a>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    </div>

    <!-- Scripts -->
    <script>
      // ##########################################
      // 7. JAVASCRIPT UNIFICADO
      // ##########################################
      
      const customModal = {
          show: function(title, message, isConfirm = false, onConfirm = () => {}, onCancel = () => {}) {
            const modal = document.getElementById('custom-modal');
            const modalTitle = document.getElementById('modal-title');
            const modalMessage = document.getElementById('modal-message');
            const modalActions = document.getElementById('modal-actions');

            modalTitle.textContent = title;
            modalMessage.textContent = message;
            modalActions.innerHTML = ''; 

            const closeBtn = document.createElement('button');
            closeBtn.textContent = isConfirm ? 'Não' : 'Fechar';
            closeBtn.className = 'px-4 py-2 text-gray-600 rounded-lg hover:bg-gray-100 transition duration-150';
            closeBtn.onclick = () => {
              modal.classList.add('hidden');
              modal.classList.remove('flex');
              onCancel();
            };

            if (isConfirm) {
              const confirmBtn = document.createElement('button');
              confirmBtn.textContent = 'Sim';
              confirmBtn.className = 'px-4 py-2 bg-red-500 text-white font-medium rounded-lg hover:bg-red-600 transition duration-150';
              confirmBtn.onclick = () => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                onConfirm();
              };
              modalActions.appendChild(closeBtn);
              modalActions.appendChild(confirmBtn);
            } else {
              modalActions.appendChild(closeBtn);
            }

            modal.classList.remove('hidden');
            modal.classList.add('flex');
          },
          alert: function(message, title = 'Atenção') {
            this.show(title, message);
          },
          confirm: function(message, title = 'Confirmação', onConfirm) {
            this.show(title, message, true, onConfirm);
          }
      };


      // Variáveis de estado do Quiz
      let userData = {};
      const QUIZ_STEPS = 4;
      let currentStep = 1;

      // Navegação do Quiz
      function showStep(step) {
          for (let i = 1; i <= QUIZ_STEPS; i++) {
              const el = document.getElementById(`step-${i}`);
              if (i === step) {
                  el.classList.remove('hidden-step');
                  el.classList.add('active-step');
              } else {
                  el.classList.add('hidden-step');
                  el.classList.remove('active-step');
              }
          }
          currentStep = step;
      }
      
      // Função para determinar o Sistema Ideal (Regra de Negócio)
      function determinarSistemaIdeal(ramo, desafio) {
          // ERP é bom para controle interno (Comércio, Gestão)
          if (ramo === 'Comércio' || ramo === 'SAAS' || desafio.includes('Gestão Financeira')) {
              return 'ERP (Gestão Integrada)';
          }
          // CRM é bom para relacionamento (Serviços, Vendas)
          if (ramo === 'Serviços' || desafio.includes('Organização de Vendas')) {
              return 'CRM (Gestão de Clientes e Vendas)';
          }
          // Site/Presença Digital (Marketing, Presença)
          if (ramo === 'Marketing' || desafio.includes('Presença Digital')) {
              return 'Site Institucional e Otimizado';
          }
          // Default ou Outros
          return 'SOLUÇÃO CUSTOMIZADA (Comunicação Direta)';
      }

      // === ETAPAS DO QUIZ ===

      // Etapa 1: Contato
      const formContato = document.getElementById('form-contato');
      if (formContato) {
          formContato.addEventListener('submit', async (e) => {
              e.preventDefault();
              const formData = new FormData(formContato);
              
              // Salva dados no estado local para o WhatsApp
              userData.nome = formData.get('nome');
              userData.telefone = formData.get('telefone');
              
              // 1. Salva dados de contato no BD (status='lead')
              try {
                  const response = await fetch('index.php?action=save_start', {
                      method: 'POST',
                      body: formData 
                  });
                  const data = await response.json();
                  
                  if (data.ok) {
                      showStep(2); // Avança para a próxima pergunta
                  } else {
                      customModal.alert(data.message || 'Erro ao salvar contato. Tente novamente.', 'Erro');
                  }
              } catch (error) {
                  customModal.alert('Erro de rede ao salvar dados.', 'Erro');
                  console.error(error);
              }
          });
      }
      
      // Etapa 2: Ramo de Atuação
      const formRamo = document.getElementById('form-quiz-ramo');
      if (formRamo) {
          formRamo.addEventListener('submit', (e) => {
              e.preventDefault();
              const formData = new FormData(formRamo);
              userData.ramo = formData.get('ramo');
              showStep(3); // Avança para a próxima pergunta
          });
      }

      // Etapa 3: Respostas Finais (Salva e Mostra Resultado)
      const formQuizFinal = document.getElementById('form-quiz-final');
      if (formQuizFinal) {
          formQuizFinal.addEventListener('submit', async (e) => {
              e.preventDefault();
              const formData = new FormData(formQuizFinal);

              // Salva dados no estado local para o WhatsApp
              userData.desafio = formData.get('desafio');
              
              // 1. Determina o Resultado
              const sistemaIdeal = determinarSistemaIdeal(userData.ramo, userData.desafio);
              
              // 2. Monta o link do WhatsApp
              const whatsappLink = document.getElementById('whatsapp-link');
              const msg = encodeURIComponent(
                  `Olá SysUp! Meu nome é ${userData.nome} (Tel: ${userData.telefone}). ` +
                  `Completei o quiz e o sistema ideal para meu negócio (${userData.ramo}) foi recomendado como: *${sistemaIdeal}*. ` +
                  `Meu maior desafio é: "${userData.desafio}". Gostaria de saber mais!`
              );
              whatsappLink.href = `https://api.whatsapp.com/send?phone=558594285201&text=${msg}`;
              
              // 3. Exibe o Resultado na tela
              document.getElementById('resultado-sistema').textContent = sistemaIdeal;
              
              // 4. Salva as respostas finais no banco de dados (status='completo')
              try {
                  // Adiciona a resposta da P2 e P3 no FormData para envio
                  formData.set('ramo', userData.ramo); 
                  
                  const response = await fetch('index.php?action=save_final', {
                      method: 'POST',
                      body: formData 
                  });
                  const data = await response.json();
                  
                  if (!data.ok) {
                      // Se não salvou, apenas avisa e mostra o resultado
                      customModal.alert('Ocorreu um erro ao finalizar o cadastro. Por favor, clique no botão para nos contatar.', 'Atenção');
                  }
                  
              } catch (error) {
                  customModal.alert('Erro de rede ao salvar o resultado final. Clique no botão de WhatsApp.', 'Atenção');
                  console.error(error);
              }

              // Sempre mostra a tela de resultado final (WhatsApp)
              showStep(4);
          });
      }


      // === LÓGICA DE ADMIN (Se logado) ===
      
      const checkAll = document.getElementById('check-all');
      const btnDelete = document.getElementById('btn-delete');
      
      if (checkAll) {
          checkAll.addEventListener('change', () => {
            document.querySelectorAll('input[name="ids[]"]').forEach(ch => ch.checked = checkAll.checked);
          });
      }

      if (btnDelete) {
          btnDelete.addEventListener('click', () => {
            const ids = Array.from(document.querySelectorAll('input[name="ids[]"]:checked')).map(c => c.value);
            if (ids.length === 0) {
              customModal.alert('Selecione ao menos um lead.');
              return;
            }
            
            customModal.confirm('Tem certeza que deseja excluir os leads selecionados?', 'Excluir Leads', async () => {
              try {
                const resp = await fetch('index.php?action=delete_leads', { 
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json' },
                  body: JSON.stringify({ ids })
                });
                const data = await resp.json();
                
                if (!resp.ok || !data.ok) {
                  customModal.alert(data.message || 'Ocorreu um erro desconhecido ao excluir.', 'Erro');
                } else {
                  window.location.reload(); 
                }
              } catch (e) {
                customModal.alert('Erro de conexão ao tentar excluir os leads.', 'Erro');
                console.error(e);
              }
            });
          });
      }

      // Inicializa o primeiro passo do quiz na página
      window.onload = () => {
        if (!document.querySelector('[name="password"]')) {
             showStep(1); 
        }
      };

    </script>
</body>
</html>