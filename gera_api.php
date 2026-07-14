<?php
//  Windows: D:/xampp/php/php.exe -f D:/xampp/htdocs/app-farm/sorte-dia/API/gera_api.php
set_time_limit(360);
setlocale(LC_ALL, 'pt_BR.utf-8', 'pt_BR', 'pt_BR.utf-8', 'portuguese');

// CONFIG
$getKey = strval(getenv('GEMINI_API_KEY'));
$thisKey = (strlen($getKey) ? $getKey : '');

define('GEMINI_API_KEY', $thisKey); 
define('GEMINI_MODEL', 'gemini-3.1-flash-lite');
define('CACHE_DIR', __DIR__ . '/cache');

// Entrada e Validações
$lingua = array('pt-BR', 'en-US', 'es-ES');
$signos = array('Aries', 'Touro', 'Gemeos', 'Cancer', 'Leao', 'Virgem', 'Libra', 'Escorpiao', 'Sagitario', 'Capricornio', 'Aquario', 'Peixes');
$idioma = ($argv[1] ?? 'pt-BR');
$data = isset($argv[2]) ? trim($argv[2]) : date('Y-m-d', strtotime('+1 day'));
$erros = null;

// Checagens
$idiomasSuportados = array(
 'pt-BR' => array('wiki' => 'pt', 'nome' => 'portugues do Brasil', 'titulo' => 'Seu dia com IA', 'cultura' => 'Use um tom próximo da cultura brasileira (misticismo popular leve, simpatias, sem ofender nenhuma religião).'),
 'en-US' => array('wiki' => 'en', 'nome' => 'English (United States)', 'titulo' => 'Your day with AI', 'cultura' => 'Use imagery familiar to US/western pop-astrology culture (four-leaf clovers, newspaper horoscopes, tarot vibes).'),
 'es-ES' => array('wiki' => 'es', 'nome' => 'espanol de Espana', 'titulo' => 'Tu día con IA', 'cultura' => 'Usa un tono cercano a la cultura hispana de la buena suerte (amuletos, tarot, tradición popular, sin ofender a nadie).')
);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
 echo 'Data inválida. Use o formato AAAA-MM-DD';
 exit;
}

if (!isset($idiomasSuportados[$idioma])) $idioma = 'pt-BR';
$infoIdioma = $idiomasSuportados[$idioma];

// Cache Local
list($ano, $mes, $dia) = explode('-', $data);
$cacheDir = CACHE_DIR . "/{$ano}/{$mes}/{$dia}";
if (!is_dir($cacheDir)) mkdir($cacheDir, 0775, true);

// Fato histórico do dia (Wikipedia "On this day")
function buscarFatoHistorico($data, $wikiIdioma) {
 $partes = explode('-', $data);
 if (count($partes) !== 3) return null;
 $mes = $partes[1];
 $dia = $partes[2];

 $url = "https://{$wikiIdioma}.wikipedia.org/api/rest_v1/feed/onthisday/events/{$mes}/{$dia}";
 $ch = curl_init($url);
 curl_setopt_array($ch, array(
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_CONNECTTIMEOUT => 5,
  CURLOPT_TIMEOUT => 10,
  CURLOPT_USERAGENT => 'LuckDayCache/1.0',
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_SSL_VERIFYHOST => 2,
  CURLOPT_HTTPHEADER => array('Accept: application/json')
 ));

 $resposta = curl_exec($ch);
 if ($resposta === false) {
  curl_close($ch);
  return null;
 }

 $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
 curl_close($ch);

 if ($httpCode !== 200) return null;
 $json = json_decode($resposta, true);
 if (!isset($json['events']) || !is_array($json['events']) || empty($json['events'])) return null;

 $evento = $json['events'][array_rand($json['events'])];
 if (empty($evento['text'])) return null;

 $ano = isset($evento['year']) ? $evento['year'] : '';
 return trim($ano . ': ' . $evento['text']);
}

// Sorteio números 
function sortearMegaSena() {
 $numeros = range(1, 60);
 shuffle($numeros);
 $resultado = array_slice($numeros, 0, 6);
 sort($resultado);
 return $resultado;
}

// Indicadores do Dia
function gerarIndicadoresDia() {
 return array(
  'amor' => rand(10, 100),
  'saudade' => rand(10, 100),
  'trabalho' => rand(10, 100),
  'financas' => rand(10, 100),
  'dinheiro' => rand(10, 100),
  'sorte' => rand(10, 100),
  'humor' => rand(10, 100),
  'energia' => rand(10, 100),
  'saude' => rand(10, 100),
  'amizade' => rand(10, 100),
  'estudos' => rand(10, 100),
  'criatividade' => rand(10, 100),
 );
}

// 3) UMA chamada de IA gera TUDO: titulo, mensagem, simbolo, cor, numero e conselho — tudo coerente entre si.
function gerarConteudoComGemini($signo, $data, $fatoHistorico, $idioma) {
 global $erros;

 switch ($idioma) {
  case 'en-US':
   $prompt = "Create a daily astrology-inspired entertainment message for the sign {signo} on the date {data}.\n" .
   "The message is strictly for entertainment and self-knowledge.\n" .
   "Do not make guaranteed predictions. Use words like 'may', 'perhaps', 'it is a good time to', and 'consider'.\n" .
   "Keep a positive, inspiring, warm, and hopeful tone.\n";
        
   if ($fatoHistorico) $prompt .= "Subtly and poetically incorporate the following historical fact from a day like today, without citing the source and without mentioning the year if it doesn't sound natural: \"{$fatoHistorico}\".\n";

   $prompt .= "Return ONLY a valid JSON, with NO Markdown and no extra text outside the JSON. \n" .
   "CRITICAL: The JSON KEYS must remain in Portuguese exactly as shown below, but all VALUES must be written in US English (using US pop-astrology culture, daily horoscope vibes, and four-leaf clovers imagery):\n" .
   "{\n" .
   '  "titulo": "Short and warm title (max 6 words)",' . "\n" .
   '  "energia_do_dia": "Message between 40 and 70 words about the energy of the day for this sign",' . "\n" .
   '  "palavra_do_dia": "A single inspiring word (e.g., Harmony, Courage, Serenity, Balance)",' . "\n" .
   '  "frase_do_dia": "Inspiring quote with up to 15 words",' . "\n" .
   '  "simbolo_emoji": "A single emoji representing the symbol of the day (e.g., 🌻, 🦋, 🔥, 🌙)",' . "\n" .
   '  "simbolo_nome": "Short name of the symbol (e.g., Sunflower)",' . "\n" .
   '  "cor_nome": "Name of an inspiring color for today (e.g., Golden)",' . "\n" .
   '  "numero_simbolico": Integer from 1 to 99,' . "\n" .
   '  "significado_numero": "Brief explanation (up to 12 words) of the symbolism of this number",' . "\n" .
   '  "conselho": "Short, practical advice for today, with a maximum of 15 words"' . "\n" .
   "}\n";
  break;

  case 'es-ES':
   $prompt = "Crea un mensaje diario de entretenimiento inspirado en la astrología para el signo {signo}, correspondiente a la fecha {data}.\n" .
   "El mensaje debe ser únicamente para entretenimiento y autoconocimiento.\n" .
   "No hagas predicciones garantizadas ni afirmaciones de que algo sucederá con certeza. Usa expresiones como 'puede', 'tal vez', 'es un buen momento para' y 'considera'.\n" .
   "Mantén un tono positivo, inspirador, acogedor y esperanzador.\n";

   if ($fatoHistorico) $prompt .= "Incorpora de forma sutil y poética el siguiente hecho histórico de un día como hoy, sin citar la fuente y sin mencionar el año si no suena natural: \"{$fatoHistorico}\".\n";

   $prompt .= "Responde ÚNICAMENTE con un JSON válido, sin Markdown y sin texto extra fuera del JSON. \n" .
   "CRÍTICO: Las CLAVES del JSON deben permanecer en portugués exactamente como se muestra abajo, pero todos los VALORES deben escribirse en Español de España (usando un tono cercano a la cultura hispana de la buena suerte, amuletos y tarot popular):\n" .
   "{\n" .
   '  "titulo": "Título corto y acogedor (máximo 6 palabras)",' . "\n" .
   '  "energia_do_dia": "Mensaje de entre 40 y 70 palabras sobre la energía del día para este signo",' . "\n" .
   '  "palavra_do_dia": "Una sola palabra inspiradora (ej: Armonía, Coraje, Serenidad, Equilibrio)",' . "\n" .
   '  "frase_do_dia": "Frase inspiradora de hasta 15 palabras",' . "\n" .
   '  "simbolo_emoji": "Un solo emoji que represente el símbolo del día (ej: 🌻, 🦋, 🔥, 🌙)",' . "\n" .
   '  "simbolo_nome": "Nombre corto del símbolo (ej: Girasol)",' . "\n" .
   '  "cor_nome": "Nombre de un color inspirador para hoy (ej: Dorado)",' . "\n" .
   '  "numero_simbolico": Número entero de 1 a 99,' . "\n" .
   '  "significado_numero": "Breve explicación (hasta 12 palabras) del simbolismo de este número",' . "\n" .
   '  "conselho": "Consejo corto y práctico para hoy, con un máximo de 15 palabras"' . "\n" .
   "}\n";
  break;

  case 'pt-BR': default:
   $prompt = "Crie uma mensagem diária de entretenimento inspirada em astrologia para o signo {signo}, referente à data {data}.\n" .
   "A mensagem deve ser apenas para entretenimento e autoconhecimento.\n" .
   "Não faça previsões garantidas nem afirmações de que algo acontecerá com certeza. Use expressões como 'pode', 'talvez', 'é um bom momento para' e 'considere'.\n" .
   "Mantenha um tom positivo, inspirador, acolhedor e esperançoso.\n";
        
   if ($fatoHistorico) $prompt .= "Incorpore de forma sutil e poética o seguinte fato histórico de um dia como hoje, sem citar a fonte e sem mencionar o ano se isso não soar natural: \"{$fatoHistorico}\".\n";

   $prompt .= "Responda APENAS com um JSON válido, sem Markdown e sem texto extra fora do JSON. \n" .
   "Todas as chaves e valores devem ser escritos em Português do Brasil (com tom próximo à cultura brasileira, misticismo leve e simpatias):\n" .
   "{\n" .
   '  "titulo": "título curto e acolhedor para o card do dia (máximo 6 palavras)",' . "\n" .
   '  "energia_do_dia": "mensagem entre 40 e 70 palavras sobre a energia do dia para este signo",' . "\n" .
   '  "palavra_do_dia": "uma única palavra inspiradora (ex: Harmonia, Coragem, Serenidade, Equilíbrio)",' . "\n" .
   '  "frase_do_dia": "frase inspiradora com até 15 palavras",' . "\n" .
   '  "simbolo_emoji": "um único emoji que represente o símbolo do dia (ex: 🌻, 🦋, 🔥, 🌙)",' . "\n" .
   '  "simbolo_nome": "nome curto do símbolo (ex: Girassol)",' . "\n" .
   '  "cor_nome": "nome de uma cor inspiradora para hoje (ex: Dourado)",' . "\n" .
   '  "numero_simbolico": número inteiro de 1 a 99,' . "\n" .
   '  "significado_numero": "breve explicação (até 12 palavras) do simbolismo desse número",' . "\n" .
   '  "conselho": "conselho curto e prático para hoje, com no máximo 15 palavras"' . "\n" .
   "}\n";
  break;
 }

 // Executa a substituição das variáveis nos templates
 $promptFinal = str_replace(array('{signo}', '{data}'), array($signo, $data), $prompt);
 $body = array(
  'contents' => array(array('parts' => array(array('text' => $promptFinal)))),
  'generationConfig' => array(
   'temperature' => 0.95,
   'maxOutputTokens' => 300, //1000,
   'responseMimeType' => 'application/json',
   'thinkingConfig' => array('thinkingBudget' => 0) // Desativa o pensamento v1beta para economizar cota de tokens e responder instantaneamente
  )
 );

 $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;
 $ch = curl_init($url);
 curl_setopt_array($ch, array(
  CURLOPT_POST => true, 
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
  CURLOPT_POSTFIELDS => json_encode($body),
  CURLOPT_TIMEOUT => 10,
  CURLOPT_CONNECTTIMEOUT => 5
 ));

 $resposta = curl_exec($ch);
 $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
 curl_close($ch);

 if ($resposta === false || $httpCode !== 200) return null;
 $json = json_decode($resposta, true);

 $texto = isset($json['candidates'][0]['content']['parts'][0]['text']) ? trim($json['candidates'][0]['content']['parts'][0]['text']) : null;
 if (!$texto) return null;

 $texto = preg_replace('/^```(json)?/i', '', trim($texto));
 $texto = preg_replace('/```$/', '', trim($texto));
 $pacote = json_decode(trim($texto), true);
 if (!$pacote || empty($pacote['energia_do_dia'])) {
  $erros = $resposta;
  return null;
 }

 return array(
  'titulo' => (isset($pacote['titulo']) ? preg_replace('/^[^:]+:\s*/', '', $pacote['titulo']) : $infoIdioma['titulo']),
  'energia_do_dia' => (isset($pacote['energia_do_dia']) ? $pacote['energia_do_dia'] : ''),
  'palavra_do_dia' => (isset($pacote['palavra_do_dia']) ? $pacote['palavra_do_dia'] : ''),
  'frase_do_dia' => (isset($pacote['frase_do_dia']) ? $pacote['frase_do_dia'] : ''),
  'simbolo_emoji' => (isset($pacote['simbolo_emoji']) ? $pacote['simbolo_emoji'] : '✨'),
  'simbolo_nome' => (isset($pacote['simbolo_nome']) ? $pacote['simbolo_nome'] : ''),
  'cor_nome' => (isset($pacote['cor_nome']) ? $pacote['cor_nome'] : ''),
  'numero_simbolico' => (isset($pacote['numero_simbolico']) ? intval($pacote['numero_simbolico']) : (1 + rand(0, 98))),
  'significado_numero' => (isset($pacote['significado_numero']) ? $pacote['significado_numero'] : ''),
  'conselho' => (isset($pacote['conselho']) ? $pacote['conselho'] : ''),
  'numeros_sorte' => sortearMegaSena(),
  'indicadores_dia' => gerarIndicadoresDia()
 );
}

// Fallback local caso IA falhar
function pacoteFallback() {
 global $erros;

 return array(
  'titulo' => 'A Fresh Start',
  'energia_do_dia' => 'Today the day invites you to slow down and notice the opportunities hidden in small moments. Trust your instincts, stay open to new perspectives, and remember that steady progress often leads to meaningful growth.',
  'palavra_do_dia' => 'Balance',
  'frase_do_dia' => 'Every small step shapes a brighter path.',
  'simbolo_emoji' => '🌿',
  'simbolo_nome' => 'Green Leaf',
  'cor_nome' => 'Emerald Green',
  'numero_simbolico' => 7,
  'significado_numero' => 'A symbol of reflection and inner wisdom.',
  'conselho' => 'Take a moment to pause before making important decisions.',
  'numeros_sorte' => sortearMegaSena(),
  'indicadores_dia' => gerarIndicadoresDia(),
  'erro' => $erros 
 );
}

// Roda de fato
if (!empty($idioma) && in_array($idioma, $lingua)) {
 foreach ($signos as $p => $sig) {
  echo ($p + 1) . ") Gerando {$sig}...\n";

  $cacheFile = "{$cacheDir}/{$sig}-{$idioma}.json";
  if (!file_exists($cacheFile)) {
   $fatoHistorico = buscarFatoHistorico($data, $infoIdioma['wiki']);
   $pacote = gerarConteudoComGemini($sig, $data, $fatoHistorico, $idioma);
   if (!$pacote) $pacote = pacoteFallback();

   $resultado = array_merge(array('signo' => $sig, 'data' => $data, 'idioma' => $idioma, 'fato_historico' => $fatoHistorico), $pacote);
   file_put_contents($cacheFile, json_encode($resultado, JSON_UNESCAPED_UNICODE));
   echo "OK => {$sig}\n";
  } else {
   echo "Ok Cache\n";
  }

  echo "------\n";
  sleep(10);
 }

 echo "Finalizado";
} else {
 echo "Sem língua...";
}
?>