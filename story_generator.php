<?php
// --- Functions (callGeminiAPI, parseGeminiStoryPlan) - Tidak berubah dari versi sebelumnya ---
// Salin fungsi callGeminiAPI() dan parseGeminiStoryPlan() dari kode Anda sebelumnya ke sini
// Pastikan parseGeminiStoryPlan() Anda menangani penomoran dengan benar seperti di versi sebelumnya.
function callGeminiAPI($apiKey, $model, $prompt) {
    // Use 'models/gemini-1.5-pro-latest' or 'models/gemini-1.5-flash-latest'
    $apiEndpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 8192
        ],
    ];

    $jsonData = json_encode($data);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiEndpoint);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180); // Tingkatkan timeout lebih lama lagi jika perlu
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Hanya untuk testing localhost jika perlu
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Hanya untuk testing localhost jika perlu

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['error' => true, 'message' => "cURL Error: " . $curlError];
    }

    if ($httpCode != 200) {
        $errorData = json_decode($response, true);
        $googleError = isset($errorData['error']['message']) ? $errorData['error']['message'] : $response;
        $detailedError = is_string($googleError) ? $googleError : json_encode($googleError);
        return ['error' => true, 'message' => "HTTP Error {$httpCode}. Response: " . $detailedError];
    }

    $responseData = json_decode($response, true);

    if (isset($responseData['error'])) {
        return ['error' => true, 'message' => "API Error: " . ($responseData['error']['message'] ?? json_encode($responseData['error']))];
    }

     if (isset($responseData['candidates'][0]['finishReason']) && !in_array($responseData['candidates'][0]['finishReason'], ['STOP', 'MAX_TOKENS'])) {
        $blockReason = $responseData['candidates'][0]['finishReason'];
        $safetyRatings = isset($responseData['candidates'][0]['safetyRatings']) ? json_encode($responseData['candidates'][0]['safetyRatings']) : 'N/A';
         if (isset($responseData['promptFeedback']['blockReason'])) {
             $blockReason .= ' (Prompt Feedback: ' . $responseData['promptFeedback']['blockReason'] . ')';
         }
        return ['error' => true, 'message' => 'Content generation stopped prematurely. Reason: ' . $blockReason . '. Safety Ratings: ' . $safetyRatings];
     }
     if (isset($responseData['promptFeedback']['blockReason'])) {
         $blockReason = $responseData['promptFeedback']['blockReason'];
         $safetyRatings = isset($responseData['promptFeedback']['safetyRatings']) ? json_encode($responseData['promptFeedback']['safetyRatings']) : 'N/A';
         return ['error' => true, 'message' => 'Prompt blocked. Reason: ' . $blockReason . '. Safety Ratings: ' . $safetyRatings];
     }

    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        return ['error' => false, 'text' => $responseData['candidates'][0]['content']['parts'][0]['text']];
    } else {
        return ['error' => true, 'message' => 'Could not extract generated text from API response structure. Full Response: ' . $response];
    }
}

function parseGeminiStoryPlan($rawText) {
    $scenes = [];
    $sceneBlocks = preg_split('/(?=^\s*(?:###|\*\*|##)\s*(?:Adegan|Scene)\s*\d+\s*[:*]?)/im', $rawText, -1, PREG_SPLIT_NO_EMPTY);

    if (count($sceneBlocks) <= 1 && !preg_match('/(?:Adegan|Scene)\s*\d+/i', $rawText)) {
         $sceneBlocks = preg_split('/\n\s*\n/', $rawText, -1, PREG_SPLIT_NO_EMPTY);
         if (count($sceneBlocks) <= 1) {
             return ['error' => true, 'message' => 'Could not reliably split text into scenes. Displaying raw output.', 'raw' => $rawText];
         }
    }

    $sceneCounter = 0;
    $actualSceneNumber = 1;

    foreach ($sceneBlocks as $block) {
        $block = trim($block);
        if (empty($block)) continue;

        $sceneData = [
            'scene_number' => $actualSceneNumber,
            'title' => 'Adegan ' . $actualSceneNumber,
            'prompt_gambar' => 'Tidak ditemukan.',
            'narasi' => 'Tidak ditemukan.',
            'voice_over' => 'Tidak ditemukan.'
        ];

        if (preg_match('/^\s*(?:###|\*\*|##)\s*(?:Adegan|Scene)\s*(\d+)\s*[:*]?(.*?)(?:\n|$)/im', $block, $titleMatches)) {
            $sceneData['scene_number'] = (int)$titleMatches[1];
            $potentialTitle = trim($titleMatches[2]);
            $sceneData['title'] = !empty($potentialTitle) ? $potentialTitle : 'Adegan ' . $sceneData['scene_number'];
            $block = trim(substr($block, strlen($titleMatches[0])));
            $actualSceneNumber = $sceneData['scene_number'];
        } else {
             $sceneData['scene_number'] = $actualSceneNumber;
             $sceneData['title'] = 'Adegan ' . $actualSceneNumber;
        }

        if (preg_match('/(?:\*\*|###|##)\s*Prompt Gambar\s*(?:\*\*|:|-)?\s*(.*?)(?=\n\s*(?:\*\*|###|##)\s*(?:Narasi|Teks Voice Over)|$)/is', $block, $matches)) {
            $sceneData['prompt_gambar'] = trim($matches[1]);
        }

        if (preg_match('/(?:\*\*|###|##)\s*Narasi(?: Cerita)?\s*(?:\*\*|:|-)?\s*(.*?)(?=\n\s*(?:\*\*|###|##)\s*(?:Teks )?Voice Over|$)/is', $block, $matches)) {
            $sceneData['narasi'] = trim($matches[1]);
        }

        if (preg_match('/(?:\*\*|###|##)\s*(?:Teks )?Voice Over\s*(?:\*\*|:|-)?\s*(.*?)$/is', $block, $matches)) {
            $sceneData['voice_over'] = trim($matches[1]);
        }

        if ($sceneData['voice_over'] === 'Tidak ditemukan.' && $sceneData['narasi'] !== 'Tidak ditemukan.') {
            $sceneData['voice_over'] = $sceneData['narasi'];
        }

         if ($sceneData['prompt_gambar'] !== 'Tidak ditemukan.' || $sceneData['narasi'] !== 'Tidak ditemukan.') {
            // Pastikan scene_number unik atau berurutan
            // Cek jika nomor sudah ada, jika iya gunakan counter berikutnya
             $existingNumbers = array_column($scenes, 'scene_number');
             if (in_array($sceneData['scene_number'], $existingNumbers)) {
                 $sceneData['scene_number'] = $actualSceneNumber; // Gunakan counter jika duplikat
                 $sceneData['title'] = 'Adegan ' . $sceneData['scene_number'];
             }
            $scenes[] = $sceneData;
            $actualSceneNumber = max(array_column($scenes, 'scene_number')) + 1; // Update counter ke nomor terbesar + 1
        } else {
             // Jika adegan kosong tapi punya header, tetap increment counter
             $actualSceneNumber++;
        }
    }

     if (empty($scenes)) {
         return ['error' => true, 'message' => 'Failed to parse any structured scenes. Displaying raw output.', 'raw' => $rawText];
     }

    return ['error' => false, 'scenes' => $scenes];
}


// --- Initialize Variables ---
$defaults = [ /* ... Salin defaults dari versi sebelumnya ... */
    'gemini_api_key' => '',
    'gemini_model' => 'gemini-1.5-flash-latest',
    'short_description' => 'tupai lucu yang rajin menanam sayur',
    'content_theme' => 'Educational',
    'language' => 'Indonesian',
    'storytelling_style' => 'Educational',
    'image_style' => "Children's Book",
    'aspect_ratio' => '16:9',
    'duration' => 'Short (1-2 minutes)',
    'num_scenes' => 5,
    'target_audience' => 'Children (6-12)',
    'content_mood' => 'Very Calm',
    'character_details' => '',
    'setting_details' => '',
    'negative_prompt' => 'buram, kualitas rendah, teks, tulisan, watermark, tanda tangan, banyak jari, cacat, deformasi',
];
$currentValues = [];
foreach ($defaults as $key => $value) {
    $postValue = isset($_POST[$key]) ? trim($_POST[$key]) : $value;
    $currentValues[$key] = htmlspecialchars(strip_tags($postValue), ENT_QUOTES, 'UTF-8');
}
$generated_output_html = '';

// --- Handle Form Submission ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (empty($currentValues['gemini_api_key'])) {
        $generated_output_html = '<div class="output-area error"><p><strong>Error:</strong> Gemini API Key is required.</p></div>';
    } else {
        // --- Construct Prompt (Gunakan versi prompt TERBARU yang menekankan konsistensi) ---
        $prompt = "Anda adalah AI penulis cerita anak dan perencana konten visual. Buatlah rencana konten cerita (story plan) yang sangat detail dan terstruktur berdasarkan spesifikasi berikut:\n\n";
        $prompt .= "**Spesifikasi Cerita:**\n";
        $prompt .= "- Deskripsi Singkat: " . $currentValues['short_description'] . "\n";
        if (!empty($currentValues['character_details'])) { $prompt .= "- Detail Karakter Utama (Gunakan ini secara KONSISTEN): " . $currentValues['character_details'] . "\n"; }
        if (!empty($currentValues['setting_details'])) { $prompt .= "- Detail Setting Utama (Gunakan ini secara KONSISTEN): " . $currentValues['setting_details'] . "\n"; }
        $prompt .= "- Tema: " . $currentValues['content_theme'] . "\n";
        $prompt .= "- Gaya Cerita: " . $currentValues['storytelling_style'] . "\n";
        $prompt .= "- Gaya Visual/Gambar: " . $currentValues['image_style'] . "\n";
        $prompt .= "- Rasio Aspek Gambar: " . $currentValues['aspect_ratio'] . "\n";
        $prompt .= "- Target Audiens: " . $currentValues['target_audience'] . "\n";
        $prompt .= "- Mood Cerita: " . $currentValues['content_mood'] . "\n";
        $prompt .= "- Jumlah Adegan Total: " . $currentValues['num_scenes'] . "\n";
        $prompt .= "- Bahasa Output: " . $currentValues['language'] . "\n\n";
        $prompt .= "**Instruksi Format Output (WAJIB DIPATUHI):**\n";
        $prompt .= "1. Bagi seluruh respons menjadi " . $currentValues['num_scenes'] . " adegan.\n";
        $prompt .= "2. Setiap adegan HARUS dimulai TEPAT dengan format heading markdown level 3: '### Adegan [Nomor Adegan]: [Judul Singkat Adegan Yang Deskriptif]'.\n";
        $prompt .= "3. Di dalam SETIAP adegan, HARUS ADA TIGA bagian berikut, masing-masing dimulai TEPAT dengan format heading bold markdown:\n";
        $prompt .= "   - '**Prompt Gambar:**' (Instruksi SANGAT PENTING: Buat deskripsi visual SANGAT DETAIL untuk AI image generator. **PRIORITAS UTAMA adalah menjaga KONSISTENSI VISUAL karakter utama dan setting utama DI SEMUA ADEGAN** berdasarkan detail yang diberikan. WAJIB MEMASUKKAN elemen berikut:\n"; // Tekankan konsistensi
        $prompt .= "        *   Aksi/Kejadian spesifik di adegan ini.\n";
        if (!empty($currentValues['character_details'])) { $prompt .= "        *   Karakter Utama (GAMBARKAN SELALU SEPERTI INI): " . $currentValues['character_details'] . "\n"; } else { $prompt .= "        *   Karakter yang relevan.\n"; }
        if (!empty($currentValues['setting_details'])) { $prompt .= "        *   Setting/Latar (GAMBARKAN SELALU SEPERTI INI, sesuaikan detail dengan adegan): " . $currentValues['setting_details'] . "\n"; } else { $prompt .= "        *   Setting/Latar yang relevan.\n"; }
        $prompt .= "        *   Gaya Visual (WAJIB KONSISTEN): " . $currentValues['image_style'] . ".\n";
        $prompt .= "        *   Mood (WAJIB KONSISTEN): " . $currentValues['content_mood'] . ".\n";
        $prompt .= "        *   Rasio Aspek (WAJIB KONSISTEN): " . $currentValues['aspect_ratio'] . ".\n";
        if (!empty($currentValues['negative_prompt'])) { $prompt .= "        *   HINDARI elemen ini (Prompt Negatif): " . $currentValues['negative_prompt'] . ".\n"; }
        $prompt .= "      Fokus pada detail spesifik (pakaian karakter jika ada, ciri khas setting) untuk memastikan kesinambungan visual antar adegan. Gunakan deskripsi yang konsisten untuk elemen kunci di setiap prompt gambar. Akhiri dengan detail teknis jika perlu.)\n"; // Tekankan detail spesifik & deskripsi konsisten
        $prompt .= "   - '**Narasi Cerita:**' (Teks narasi lengkap adegan dalam bahasa " . $currentValues['language'] . ".)\n";
        $prompt .= "   - '**Teks Voice Over:**' (Teks persis untuk dibacakan dalam bahasa " . $currentValues['language'] . ". Jika sama dengan narasi, tulis ulang narasinya.)\n";
        $prompt .= "4. TIDAK ADA teks/format lain SEBELUM heading adegan pertama atau DI ANTARA bagian dalam adegan.\n";
        $prompt .= "5. Buat alur cerita koheren, menarik, konsisten dengan semua spesifikasi.\n\n";
        $prompt .= "--- MULAI RENCANA KONTEN ---\n";

        // --- Call API ---
        $apiResult = callGeminiAPI($currentValues['gemini_api_key'], $currentValues['gemini_model'], $prompt);

        // --- Process Result (Modify HTML generation) ---
        $generated_output_html = '<div class="output-area">';
        if ($apiResult['error']) {
             $generated_output_html .= '<h2 class="error">Error Generating Content</h2>';
             $generated_output_html .= '<p class="error-message"><strong>Details:</strong> ' . htmlspecialchars($apiResult['message'], ENT_QUOTES, 'UTF-8') . '</p>';
             $generated_output_html .= '<p><i>(Troubleshooting: Periksa API Key, koneksi, kuota, prompt, atau safety settings.)</i></p>';
        } else {
            $parsedData = parseGeminiStoryPlan($apiResult['text']);
            $generated_output_html .= '<h2>Generated Story Plan (via Gemini ' . htmlspecialchars($currentValues['gemini_model'], ENT_QUOTES, 'UTF-8') . ')</h2>';

            if ($parsedData['error']) {
                $generated_output_html .= '<p class="warning"><strong>Note:</strong> ' . htmlspecialchars($parsedData['message'], ENT_QUOTES, 'UTF-8') . '</p>';
                $generated_output_html .= '<pre class="raw-output">' . htmlspecialchars($parsedData['raw'] ?? $apiResult['text'], ENT_QUOTES, 'UTF-8') . '</pre>';
            } else {
                // Export Buttons
                $generated_output_html .= '<div class="export-buttons">';
                $generated_output_html .= '<button id="exportTxtBtn" class="export-button">Ekspor .txt</button>';
                $generated_output_html .= '<button id="exportCsvBtn" class="export-button">Ekspor .csv</button>';
                $generated_output_html .= '<button id="exportJsonBtn" class="export-button">Ekspor .json</button>';
                $generated_output_html .= '</div>';

                 // --- Add Scene Button ---
                 $generated_output_html .= '<div class="add-scene-controls">';
                 $generated_output_html .= '<button id="addSceneBtn" class="add-scene-button">➕ Tambah Adegan Baru</button>';
                 $generated_output_html .= '</div>';
                 // --- End Add Scene Button ---

                $generated_output_html .= '<div class="structured-output" id="structuredOutputContainer">';
                foreach ($parsedData['scenes'] as $index => $scene) {
                    $sceneNumber = $scene['scene_number'] ?? ($index + 1); // Use parsed number or fallback index+1
                    // Generate unique IDs based on the reliable scene number
                    $promptId = 'prompt-text-' . $sceneNumber;
                    $narasiId = 'narasi-text-' . $sceneNumber;
                    $voId = 'vo-text-' . $sceneNumber;
                    $cardId = 'scene-card-' . $sceneNumber; // ID for the whole card

                    $generated_output_html .= '<div class="scene-card" id="' . $cardId . '" data-scene-number="' . $sceneNumber . '">'; // Add ID and data-attribute
                    $sceneTitle = htmlspecialchars($scene['title'] ?? ('Adegan ' . $sceneNumber), ENT_QUOTES, 'UTF-8');
                    // Ensure title matches the scene number for consistency
                    $generated_output_html .= '<h3 class="scene-title">' . $sceneTitle . '</h3>'; // Add class for renumbering

                     // --- Delete Scene Button ---
                     $generated_output_html .= '<button class="delete-scene-btn" title="Hapus Adegan Ini">🗑️ Hapus</button>';
                     // --- End Delete Scene Button ---


                    $promptGambar_html = nl2br(htmlspecialchars($scene['prompt_gambar'] ?? '', ENT_QUOTES, 'UTF-8')); // Default to empty string
                    $narasi_html = nl2br(htmlspecialchars($scene['narasi'] ?? '', ENT_QUOTES, 'UTF-8'));
                    $voiceOver_html = nl2br(htmlspecialchars($scene['voice_over'] ?? '', ENT_QUOTES, 'UTF-8'));

                    // --- Prompt Gambar Section with Edit, Copy, Regenerate ---
                    $generated_output_html .= '<div class="scene-section">';
                    $generated_output_html .= '<h4><i class="icon">🖼️</i> Prompt Gambar Cerita</h4>';
                    // Add contenteditable="true"
                    $generated_output_html .= '<p class="editable-text" id="' . $promptId . '" contenteditable="true">' . $promptGambar_html . '</p>';
                    $generated_output_html .= '<div class="section-actions">'; // Container for buttons
                    $generated_output_html .= '<button class="copy-button" data-target-id="' . $promptId . '" title="Salin Prompt Gambar">📋 Salin</button>';
                    // Placeholder Regenerate Button
                    $generated_output_html .= '<button class="regenerate-button" data-section="prompt_gambar" title="Regenerate Prompt Gambar (Placeholder)">🔄 Regenerate</button>';
                    $generated_output_html .= '</div>'; // end section-actions
                    $generated_output_html .= '</div>';

                    // --- Narasi Cerita Section with Edit, Copy, Regenerate ---
                    $generated_output_html .= '<div class="scene-section">';
                    $generated_output_html .= '<h4><i class="icon">📖</i> Narasi Cerita</h4>';
                    $generated_output_html .= '<p class="editable-text" id="' . $narasiId . '" contenteditable="true">' . $narasi_html . '</p>';
                     $generated_output_html .= '<div class="section-actions">';
                    $generated_output_html .= '<button class="copy-button" data-target-id="' . $narasiId . '" title="Salin Narasi">📋 Salin</button>';
                    $generated_output_html .= '<button class="regenerate-button" data-section="narasi" title="Regenerate Narasi (Placeholder)">🔄 Regenerate</button>';
                    $generated_output_html .= '</div>';
                    $generated_output_html .= '</div>';

                    // --- Teks Voice Over Section with Edit, Copy, Regenerate ---
                    $generated_output_html .= '<div class="scene-section">';
                    $generated_output_html .= '<h4><i class="icon">🎤</i> Teks Voice Over</h4>';
                    $generated_output_html .= '<p class="editable-text" id="' . $voId . '" contenteditable="true">' . $voiceOver_html . '</p>';
                    $generated_output_html .= '<div class="section-actions">';
                    $generated_output_html .= '<button class="copy-button" data-target-id="' . $voId . '" title="Salin Teks Voice Over">📋 Salin</button>';
                    $generated_output_html .= '<button class="regenerate-button" data-section="voice_over" title="Regenerate Voice Over (Placeholder)">🔄 Regenerate</button>';
                     $generated_output_html .= '</div>';
                    $generated_output_html .= '</div>';

                    $generated_output_html .= '</div>'; // end scene-card
                }
                $generated_output_html .= '</div>'; // end structured-output
            }
        }
        $generated_output_html .= '</div>'; // Close output-area
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generator Ide Konten Cerita By ATADIGITAL</title>
    <style>
        /* --- Basic Styling (Keep previous) --- */
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; margin: 20px; background-color: #f8f9fa; color: #343a40; }
        .container { max-width: 1000px; margin: 20px auto; background-color: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        h1, h2 { color: #0056b3; border-bottom: 2px solid #dee2e6; padding-bottom: 10px; margin-bottom: 25px; }
        h1 { font-size: 1.8em; }
        h2 { font-size: 1.5em; margin-top: 30px; }
        h2.error { color: #dc3545; border-bottom-color: #f5c6cb; }
        .form-section { margin-bottom: 30px; padding: 25px; border: 1px solid #e9ecef; border-radius: 6px; background-color: #ffffff; }
        .form-section h3 { margin-top: 0; margin-bottom: 20px; color: #495057; font-size: 1.2em; border-bottom: 1px solid #f1f3f5; padding-bottom: 8px;}
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 25px; }
        .form-group { display: flex; flex-direction: column; }
        label { font-weight: 600; margin-bottom: 6px; color: #495057; font-size: 0.95em; }
        label.warning { color: #c82333; font-weight: normal; font-size: 0.85em; margin-top: 4px; display: block;}
        input[type="text"], input[type="password"], input[type="number"], select, textarea { padding: 10px 12px; border: 1px solid #ced4da; border-radius: 4px; font-size: 1em; width: 100%; box-sizing: border-box; transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out; font-family: inherit; }
        textarea { resize: vertical; min-height: 60px; }
        input:focus, select:focus, textarea:focus { border-color: #80bdff; outline: 0; box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25); }
        .form-group small { font-size: 0.85em; color: #6c757d; margin-top: 5px; }
        .submit-button { background-color: #007bff; color: white; padding: 12px 30px; border: none; border-radius: 5px; font-size: 1.1em; font-weight: 600; cursor: pointer; transition: background-color 0.3s ease, opacity 0.3s ease; display: block; margin: 30px auto 0 auto; width: fit-content; }
        .submit-button:hover { background-color: #0056b3; }
        .submit-button:disabled { background-color: #6c757d; cursor: not-allowed; opacity: 0.7; }

        /* --- Output Area Styling --- */
        .output-area { margin-top: 40px; padding: 25px; border: 1px solid #e9ecef; border-radius: 6px; background-color: #f8f9fa; overflow-wrap: break-word; position: relative; }
        .output-area.error { border-color: #f5c6cb; background-color: #f8d7da; color: #721c24; }
        .output-area h2 { border-bottom: none; margin-bottom: 20px; }
        p.warning { color: #856404; background-color: #fff3cd; border: 1px solid #ffeeba; padding: 12px; border-radius: 4px; margin-bottom: 15px; }
        .error-message { color: #721c24; font-weight: bold; }
        pre.raw-output { white-space: pre-wrap; font-family: Consolas, Monaco, 'Andale Mono', 'Ubuntu Mono', monospace; background-color: #e9ecef; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; border: 1px solid #ced4da; font-size: 0.9em; }

        /* --- Structured Output Styling --- */
        .structured-output { margin-top: 15px; }
        .scene-card { background-color: #ffffff; border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 25px; padding: 25px; box-shadow: 0 2px 6px rgba(0,0,0,0.06); transition: box-shadow 0.2s ease-in-out; position: relative; /* For delete button positioning */ }
        .scene-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .scene-card h3 { margin-top: 0; margin-bottom: 20px; color: #0056b3; border-bottom: 1px solid #e9ecef; padding-bottom: 10px; font-size: 1.4em; font-weight: 600; padding-right: 40px; /* Space for delete button */ }
        .scene-section { margin-bottom: 20px; padding-left: 15px; border-left: 4px solid #007bff; }
        .scene-section:last-child { margin-bottom: 0; }
        .scene-section h4 { margin-top: 0; margin-bottom: 10px; color: #343a40; font-size: 1.15em; font-weight: 600; display: flex; align-items: center; }
        .scene-section h4 i.icon { margin-right: 10px; font-style: normal; font-size: 1.3em; color: #007bff; }
        .scene-section p { margin: 0 0 10px 0; color: #495057; line-height: 1.7; font-size: 1em; }

        /* --- Inline Editing Styling --- */
        .editable-text[contenteditable="true"] {
            padding: 5px;
            border: 1px dashed transparent; /* Subtle indicator */
            min-height: 1.6em; /* Ensure clickable area */
            cursor: text;
            transition: border-color 0.2s ease, background-color 0.2s ease;
        }
        .editable-text[contenteditable="true"]:hover {
            border-color: #adb5bd; /* Dashed border on hover */
        }
        .editable-text[contenteditable="true"]:focus {
            outline: 2px solid #80bdff;
            border-color: transparent; /* Hide dashed border on focus */
            background-color: #eef8ff; /* Slight background highlight */
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
        }

        /* --- Action Buttons Styling --- */
        .section-actions {
            display: flex;
            gap: 8px; /* Space between buttons */
            align-items: center;
            margin-top: 5px; /* Space above buttons */
        }
        .copy-button, .regenerate-button {
            background-color: #e9ecef;
            color: #495057;
            border: 1px solid #ced4da;
            padding: 3px 8px;
            font-size: 0.8em;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s ease, color 0.2s ease;
            line-height: 1.4; /* Adjust vertical alignment */
        }
        .copy-button:hover, .regenerate-button:hover { background-color: #d1d5db; }
        .copy-button.copied { background-color: #28a745; color: white; border-color: #218838; }
        .regenerate-button { /* Specific style if needed */ }

        /* --- Export Buttons Styling --- */
        .export-buttons { margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #dee2e6; display: flex; gap: 10px; flex-wrap: wrap; }
        .export-button { background-color: #6c757d; color: white; padding: 8px 15px; border: none; border-radius: 4px; font-size: 0.9em; cursor: pointer; transition: background-color 0.2s ease; }
        .export-button:hover { background-color: #5a6268; }

         /* --- Add/Delete Scene Button Styling --- */
         .add-scene-controls {
            margin-bottom: 20px; /* Space after add button */
         }
         .add-scene-button {
            background-color: #28a745;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            font-size: 0.9em;
            cursor: pointer;
            transition: background-color 0.2s ease;
         }
         .add-scene-button:hover { background-color: #218838; }
         .delete-scene-btn {
             position: absolute;
             top: 20px; /* Adjust position */
             right: 20px;
             background: none;
             border: none;
             color: #dc3545; /* Red color for delete */
             font-size: 1.4em; /* Make icon larger */
             cursor: pointer;
             padding: 5px;
             line-height: 1;
             transition: color 0.2s ease;
         }
        .delete-scene-btn:hover { color: #c82333; /* Darker red on hover */ }


        /* --- Loading Indicator Styling --- */
        #loadingIndicator { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background-color: rgba(0, 0, 0, 0.7); color: white; padding: 20px 30px; border-radius: 8px; font-size: 1.2em; z-index: 1000; text-align: center; display: none; }
        #loadingIndicator::after { content: ""; display: block; width: 20px; height: 20px; margin: 10px auto 0; border: 3px solid rgba(255, 255, 255, 0.3); border-radius: 50%; border-top-color: #fff; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

    </style>
</head>
<body>

<div id="loadingIndicator">Memproses permintaan...<br>Mohon tunggu sebentar.</div>

<div class="container">
    <h1>Generator Ide Konten Cerita By ATADIGITAL</h1>
    <p>Masukkan detail cerita Anda, generate, lalu edit, tambah/hapus adegan sesuai kebutuhan.</p>

    <form id="storyForm" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES, 'UTF-8'); ?>" method="post">
        <!-- Form Sections (API, Content, Detail Tambahan, Advanced) -->
        <!-- Salin semua form-section dari kode Anda sebelumnya ke sini -->
        <!-- ... (FORM CONTENT AS BEFORE) ... -->
         <!-- API Settings Section -->
        <div class="form-section">
            <h3>Pengaturan API</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="gemini_api_key">Gemini API Key</label>
                    <input type="password" id="gemini_api_key" name="gemini_api_key" value="<?php echo htmlspecialchars($currentValues['gemini_api_key'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    <label class="warning">Wajib diisi. Jaga kerahasiaan API Key Anda!</label>
                </div>
                <div class="form-group">
                    <label for="gemini_model">Pilih Model Gemini</label>
                    <select id="gemini_model" name="gemini_model">
                        <option value="gemini-1.5-flash-latest" <?php echo (($currentValues['gemini_model'] ?? '') == 'gemini-1.5-flash-latest') ? 'selected' : ''; ?>>Gemini 1.5 Flash (Terbaru)</option>
                        <option value="gemini-1.5-pro-latest" <?php echo (($currentValues['gemini_model'] ?? '') == 'gemini-1.5-pro-latest') ? 'selected' : ''; ?>>Gemini 1.5 Pro (Terbaru)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Content Settings Section -->
        <div class="form-section">
            <h3>Pengaturan Konten</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="short_description">Deskripsi Singkat Cerita</label>
                    <input type="text" id="short_description" name="short_description" value="<?php echo htmlspecialchars($currentValues['short_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="content_theme">Tema Konten</label>
                    <input type="text" id="content_theme" name="content_theme" value="<?php echo htmlspecialchars($currentValues['content_theme'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group">
                    <label for="language">Bahasa</label>
                    <select id="language" name="language">
                        <option value="English" <?php echo (($currentValues['language'] ?? '') == 'English') ? 'selected' : ''; ?>>English</option>
                        <option value="Indonesian" <?php echo (($currentValues['language'] ?? '') == 'Indonesian') ? 'selected' : ''; ?>>Indonesian</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="storytelling_style">Gaya Bercerita</label>
                    <select id="storytelling_style" name="storytelling_style">
                         <option value="Educational" <?php echo (($currentValues['storytelling_style'] ?? '') == 'Educational') ? 'selected' : ''; ?>>Edukasi</option>
                        <option value="Funny" <?php echo (($currentValues['storytelling_style'] ?? '') == 'Funny') ? 'selected' : ''; ?>>Lucu</option>
                        <option value="Adventure" <?php echo (($currentValues['storytelling_style'] ?? '') == 'Adventure') ? 'selected' : ''; ?>>Petualangan</option>
                         <option value="Calm" <?php echo (($currentValues['storytelling_style'] ?? '') == 'Calm') ? 'selected' : ''; ?>>Tenang</option>
                         <option value="Mysterious" <?php echo (($currentValues['storytelling_style'] ?? '') == 'Mysterious') ? 'selected' : ''; ?>>Misterius</option>
                         <option value="Heartwarming" <?php echo (($currentValues['storytelling_style'] ?? '') == 'Heartwarming') ? 'selected' : ''; ?>>Menyentuh Hati</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="image_style">Gaya Gambar</label>
                    <select id="image_style" name="image_style">
                         <option value="Children's Book" <?php echo (($currentValues['image_style'] ?? '') == "Children's Book") ? 'selected' : ''; ?>>Buku Anak</option>
                        <option value="Cartoon" <?php echo (($currentValues['image_style'] ?? '') == 'Cartoon') ? 'selected' : ''; ?>>Kartun</option>
                        <option value="Realistic" <?php echo (($currentValues['image_style'] ?? '') == 'Realistic') ? 'selected' : ''; ?>>Realistis</option>
                        <option value="Anime" <?php echo (($currentValues['image_style'] ?? '') == 'Anime') ? 'selected' : ''; ?>>Anime</option>
                        <option value="Pixel Art" <?php echo (($currentValues['image_style'] ?? '') == 'Pixel Art') ? 'selected' : ''; ?>>Pixel Art</option>
                         <option value="Watercolor" <?php echo (($currentValues['image_style'] ?? '') == 'Watercolor') ? 'selected' : ''; ?>>Cat Air</option>
                         <option value="3D Render" <?php echo (($currentValues['image_style'] ?? '') == '3D Render') ? 'selected' : ''; ?>>3D Render</option>
                         <option value="Boosters" <?php echo (($currentValues['image_style'] ?? '') == 'Boosters') ? 'selected' : ''; ?>>Boosters</option>
                        <option value="Trending on ArtStation" <?php echo (($currentValues['image_style'] ?? '') == 'Trending on ArtStation') ? 'selected' : ''; ?>>Trending on ArtStation</option>
                        <option value="Beautiful" <?php echo (($currentValues['image_style'] ?? '') == 'Beautiful') ? 'selected' : ''; ?>>Beautiful</option>
                        <option value="Vivid" <?php echo (($currentValues['image_style'] ?? '') == 'Vivid') ? 'selected' : ''; ?>>Vivid</option>
                        <option value="Extremely Detailed" <?php echo (($currentValues['image_style'] ?? '') == 'Extremely Detailed') ? 'selected' : ''; ?>>Extremely Detailed</option>
                        <option value="Stunning" <?php echo (($currentValues['image_style'] ?? '') == 'Stunning') ? 'selected' : ''; ?>>Stunning</option>
                        <option value="Wondrous" <?php echo (($currentValues['image_style'] ?? '') == 'Wondrous') ? 'selected' : ''; ?>>Wondrous</option>
                        <option value="Fantastic" <?php echo (($currentValues['image_style'] ?? '') == 'Fantastic') ? 'selected' : ''; ?>>Fantastic</option>
                        <option value="Contest Winner" <?php echo (($currentValues['image_style'] ?? '') == 'Contest Winner') ? 'selected' : ''; ?>>Contest Winner</option>
                        <option value="Detailed" <?php echo (($currentValues['image_style'] ?? '') == 'Detailed') ? 'selected' : ''; ?>>Detailed</option>
                        <option value="Postprocessing" <?php echo (($currentValues['image_style'] ?? '') == 'Postprocessing') ? 'selected' : ''; ?>>Postprocessing</option>
                        <option value="Magnificent" <?php echo (($currentValues['image_style'] ?? '') == 'Magnificent') ? 'selected' : ''; ?>>Magnificent</option>
                        <option value="8K" <?php echo (($currentValues['image_style'] ?? '') == '8K') ? 'selected' : ''; ?>>8K</option>
                        <option value="4K Resolution" <?php echo (($currentValues['image_style'] ?? '') == '4K Resolution') ? 'selected' : ''; ?>>4K Resolution</option>
                        <option value="High Detail" <?php echo (($currentValues['image_style'] ?? '') == 'High Detail') ? 'selected' : ''; ?>>High Detail</option>
                        <option value="Hyperrealistic" <?php echo (($currentValues['image_style'] ?? '') == 'Hyperrealistic') ? 'selected' : ''; ?>>Hyperrealistic</option>
                        <option value="Photorealistic" <?php echo (($currentValues['image_style'] ?? '') == 'Photorealistic') ? 'selected' : ''; ?>>Photorealistic</option>
                        <option value="Gorgeous" <?php echo (($currentValues['image_style'] ?? '') == 'Gorgeous') ? 'selected' : ''; ?>>Gorgeous</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="aspect_ratio">Aspect Ratio Gambar</label>
                    <select id="aspect_ratio" name="aspect_ratio">
                        <option value="16:9" <?php echo (($currentValues['aspect_ratio'] ?? '') == '16:9') ? 'selected' : ''; ?>>16:9 (Landscape)</option>
                        <option value="9:16" <?php echo (($currentValues['aspect_ratio'] ?? '') == '9:16') ? 'selected' : ''; ?>>9:16 (Portrait)</option>
                        <option value="1:1" <?php echo (($currentValues['aspect_ratio'] ?? '') == '1:1') ? 'selected' : ''; ?>>1:1 (Square)</option>
                        <option value="4:3" <?php echo (($currentValues['aspect_ratio'] ?? '') == '4:3') ? 'selected' : ''; ?>>4:3 (Traditional TV)</option>
                        <option value="3:2" <?php echo (($currentValues['aspect_ratio'] ?? '') == '3:2') ? 'selected' : ''; ?>>3:2 (Photography)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="duration">Perkiraan Durasi Video</label>
                    <select id="duration" name="duration">
                         <option value="Very Short (Under 1 min)" <?php echo (($currentValues['duration'] ?? '') == 'Very Short (Under 1 min)') ? 'selected' : ''; ?>>Sangat Pendek (< 1 menit)</option>
                        <option value="Short (1-2 minutes)" <?php echo (($currentValues['duration'] ?? '') == 'Short (1-2 minutes)') ? 'selected' : ''; ?>>Pendek (1-2 menit)</option>
                        <option value="Medium (3-5 minutes)" <?php echo (($currentValues['duration'] ?? '') == 'Medium (3-5 minutes)') ? 'selected' : ''; ?>>Sedang (3-5 menit)</option>
                        <option value="Long (5+ minutes)" <?php echo (($currentValues['duration'] ?? '') == 'Long (5+ minutes)') ? 'selected' : ''; ?>>Panjang (5+ menit)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Detail Tambahan Section -->
        <div class="form-section">
            <h3>Detail Tambahan (Opsional)</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="character_details">Detail Karakter Utama</label>
                    <textarea id="character_details" name="character_details" rows="4" placeholder="Contoh: Tupi si tupai ceria..."><?php echo htmlspecialchars($currentValues['character_details'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <small>Jelaskan penampilan, sifat, atau ciri khas karakter utama agar konsisten.</small>
                </div>
                <div class="form-group">
                    <label for="setting_details">Detail Setting/Latar Utama</label>
                    <textarea id="setting_details" name="setting_details" rows="4" placeholder="Contoh: Hutan pinus yang damai..."><?php echo htmlspecialchars($currentValues['setting_details'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <small>Deskripsikan lokasi utama cerita agar konsisten dalam gambar.</small>
                </div>
                <div class="form-group">
                    <label for="negative_prompt">Prompt Negatif untuk Gambar</label>
                    <textarea id="negative_prompt" name="negative_prompt" rows="3" placeholder="Contoh: buram, kualitas rendah..."><?php echo htmlspecialchars($currentValues['negative_prompt'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <small>Sebutkan hal-hal yang TIDAK boleh muncul dalam gambar (pisahkan dengan koma).</small>
                </div>
            </div>
        </div>

        <!-- Advanced Settings Section -->
        <div class="form-section">
            <h3>Pengaturan Lanjutan</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="num_scenes">Jumlah Adegan (Awal)</label> <!-- Label sedikit diubah -->
                    <input type="number" id="num_scenes" name="num_scenes" value="<?php echo htmlspecialchars($currentValues['num_scenes'] ?? '5', ENT_QUOTES, 'UTF-8'); ?>" min="1" max="20">
                     <small>Jumlah adegan yang diminta dari AI awalnya.</small>
                </div>
                <div class="form-group">
                    <label for="target_audience">Target Audiens</label>
                    <select id="target_audience" name="target_audience">
                         <option value="Preschoolers (3-5)" <?php echo (($currentValues['target_audience'] ?? '') == 'Preschoolers (3-5)') ? 'selected' : ''; ?>>Anak Pra Sekolah (3-5 thn)</option>
                        <option value="Children (6-12)" <?php echo (($currentValues['target_audience'] ?? '') == 'Children (6-12)') ? 'selected' : ''; ?>>Anak-anak (6-12 thn)</option>
                        <option value="Teens (13-17)" <?php echo (($currentValues['target_audience'] ?? '') == 'Teens (13-17)') ? 'selected' : ''; ?>>Remaja (13-17 thn)</option>
                         <option value="Young Adults (18-25)" <?php echo (($currentValues['target_audience'] ?? '') == 'Young Adults (18-25)') ? 'selected' : ''; ?>>Dewasa Muda (18-25 thn)</option>
                        <option value="General Audience" <?php echo (($currentValues['target_audience'] ?? '') == 'General Audience') ? 'selected' : ''; ?>>Umum</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="content_mood">Mood Konten</label>
                    <select id="content_mood" name="content_mood">
                         <option value="Very Calm" <?php echo (($currentValues['content_mood'] ?? '') == 'Very Calm') ? 'selected' : ''; ?>>Sangat Tenang</option>
                        <option value="Calm" <?php echo (($currentValues['content_mood'] ?? '') == 'Calm') ? 'selected' : ''; ?>>Tenang</option>
                        <option value="Neutral" <?php echo (($currentValues['content_mood'] ?? '') == 'Neutral') ? 'selected' : ''; ?>>Netral</option>
                        <option value="Happy" <?php echo (($currentValues['content_mood'] ?? '') == 'Happy') ? 'selected' : ''; ?>>Senang</option>
                        <option value="Energetic" <?php echo (($currentValues['content_mood'] ?? '') == 'Energetic') ? 'selected' : ''; ?>>Energik</option>
                         <option value="Exciting" <?php echo (($currentValues['content_mood'] ?? '') == 'Exciting') ? 'selected' : ''; ?>>Menyenangkan</option>
                        <option value="Intense" <?php echo (($currentValues['content_mood'] ?? '') == 'Intense') ? 'selected' : ''; ?>>Intens</option>
                         <option value="Mysterious" <?php echo (($currentValues['content_mood'] ?? '') == 'Mysterious') ? 'selected' : ''; ?>>Misterius</option>
                          <option value="Sad" <?php echo (($currentValues['content_mood'] ?? '') == 'Sad') ? 'selected' : ''; ?>>Sedih</option>
                    </select>
                </div>
            </div>
        </div>
        <!-- End Form Sections -->

        <button type="submit" id="submitBtn" class="submit-button">Generate Storytelling</button>
    </form>

    <?php
    // Display the generated output HTML
    echo $generated_output_html;
    ?>

</div> <!-- end container -->

<!-- JavaScript Section -->
<script>
    // --- Loading Indicator ---
    const storyForm = document.getElementById('storyForm');
    const submitBtn = document.getElementById('submitBtn');
    const loadingIndicator = document.getElementById('loadingIndicator');
    if (storyForm && submitBtn && loadingIndicator) {
        storyForm.addEventListener('submit', function() {
            loadingIndicator.style.display = 'block';
            submitBtn.disabled = true;
            submitBtn.textContent = 'Memproses...';
        });
    }

    // --- Helper: Renumber Scenes ---
    function renumberScenes() {
        const container = document.getElementById('structuredOutputContainer');
        if (!container) return;
        const sceneCards = container.querySelectorAll('.scene-card');
        sceneCards.forEach((card, index) => {
            const newSceneNumber = index + 1;
            card.dataset.sceneNumber = newSceneNumber; // Update data attribute
            card.id = `scene-card-${newSceneNumber}`; // Update card ID

            const titleElement = card.querySelector('.scene-title');
            if (titleElement) {
                // Update title text reliably
                const currentTitleText = titleElement.innerText;
                 // Try to preserve custom title part
                 const titleMatch = currentTitleText.match(/(?:Adegan|Scene)\s*\d+\s*[:*]?(.*)/i);
                 const customPart = titleMatch && titleMatch[1] ? titleMatch[1].trim() : `Adegan ${newSceneNumber}`; // Fallback title
                 titleElement.innerText = `Adegan ${newSceneNumber}: ${customPart}`;
            }

            // Update IDs and data-target-id for internal elements/buttons
            const sections = ['prompt_gambar', 'narasi', 'voice_over'];
            sections.forEach(sectionKey => {
                const pElement = card.querySelector(`#${sectionKey.replace('_','-')}-text-${card.dataset.originalScenenumber || newSceneNumber}`); // Find by old or new ID maybe? Simpler: find by class within card
                const pInSection = card.querySelector(`.scene-section p[id^="${sectionKey.replace('_','-')}-text-"]`);
                 const copyBtnInSection = card.querySelector(`.scene-section .copy-button[data-target-id^="${sectionKey.replace('_','-')}-text-"]`);
                 const regenBtnInSection = card.querySelector(`.scene-section .regenerate-button[data-section="${sectionKey}"]`);

                if(pInSection) {
                    const newPId = `${sectionKey.replace('_','-')}-text-${newSceneNumber}`;
                    pInSection.id = newPId;
                     if(copyBtnInSection) copyBtnInSection.dataset.targetId = newPId;
                    // Note: Regenerate button doesn't rely on text ID, only scene number (which is on card)
                 }

            });
             // Store original number if needed for complex ID lookups, though simpler to query within card
             // card.dataset.originalScenenumber = card.dataset.sceneNumber; // Store old number if needed by other logic


        });
    }


    // --- Helper: Create New Scene Card HTML ---
    function createNewSceneCardHTML(sceneNumber) {
        const cardId = `scene-card-${sceneNumber}`;
        const promptId = `prompt-text-${sceneNumber}`;
        const narasiId = `narasi-text-${sceneNumber}`;
        const voId = `vo-text-${sceneNumber}`;

        // Basic structure for a new scene card
        return `
            <div class="scene-card" id="${cardId}" data-scene-number="${sceneNumber}">
                <h3 class="scene-title">Adegan ${sceneNumber}: Judul Adegan Baru</h3>
                <button class="delete-scene-btn" title="Hapus Adegan Ini">🗑️ Hapus</button>

                <div class="scene-section">
                    <h4><i class="icon">🖼️</i> Prompt Gambar Cerita</h4>
                    <p class="editable-text" id="${promptId}" contenteditable="true"></p>
                    <div class="section-actions">
                        <button class="copy-button" data-target-id="${promptId}" title="Salin Prompt Gambar">📋 Salin</button>
                        <button class="regenerate-button" data-section="prompt_gambar" title="Regenerate Prompt Gambar (Placeholder)">🔄 Regenerate</button>
                    </div>
                </div>

                <div class="scene-section">
                    <h4><i class="icon">📖</i> Narasi Cerita</h4>
                    <p class="editable-text" id="${narasiId}" contenteditable="true"></p>
                    <div class="section-actions">
                        <button class="copy-button" data-target-id="${narasiId}" title="Salin Narasi">📋 Salin</button>
                        <button class="regenerate-button" data-section="narasi" title="Regenerate Narasi (Placeholder)">🔄 Regenerate</button>
                    </div>
                </div>

                <div class="scene-section">
                    <h4><i class="icon">🎤</i> Teks Voice Over</h4>
                    <p class="editable-text" id="${voId}" contenteditable="true"></p>
                    <div class="section-actions">
                        <button class="copy-button" data-target-id="${voId}" title="Salin Teks Voice Over">📋 Salin</button>
                        <button class="regenerate-button" data-section="voice_over" title="Regenerate Voice Over (Placeholder)">🔄 Regenerate</button>
                    </div>
                </div>
            </div>
        `;
    }


    // --- Event Delegation for Dynamic Content (Copy, Delete, Regenerate) ---
    const outputArea = document.querySelector('.output-area');
    if (outputArea) {
        outputArea.addEventListener('click', function(event) {
            const target = event.target;

            // --- Handle Copy Button ---
            if (target.classList.contains('copy-button')) {
                const targetId = target.dataset.targetId;
                const textElement = document.getElementById(targetId);
                if (textElement) {
                    navigator.clipboard.writeText(textElement.innerText).then(() => {
                        const originalText = target.innerHTML;
                        target.textContent = 'Tersalin!';
                        target.classList.add('copied');
                        setTimeout(() => {
                            target.innerHTML = originalText;
                            target.classList.remove('copied');
                        }, 1500);
                    }).catch(err => {
                        console.error('Gagal menyalin:', err);
                        alert('Gagal menyalin teks.');
                    });
                }
            }

            // --- Handle Delete Scene Button ---
            else if (target.classList.contains('delete-scene-btn')) {
                 const sceneCard = target.closest('.scene-card'); // Find parent card
                 if (sceneCard && confirm('Anda yakin ingin menghapus adegan ini?')) {
                     sceneCard.remove(); // Remove the card from DOM
                     renumberScenes(); // Renumber remaining scenes
                 }
            }

            // --- Handle Regenerate Button (Placeholder) ---
             else if (target.classList.contains('regenerate-button')) {
                 const sceneCard = target.closest('.scene-card');
                 const section = target.dataset.section;
                 const sceneNumber = sceneCard ? sceneCard.dataset.sceneNumber : 'unknown';

                 alert(`Fungsi "Regenerate" untuk bagian "${section}" di Adegan ${sceneNumber} belum diimplementasikan.\nIni memerlukan logika server tambahan (AJAX & PHP) untuk memanggil API Gemini lagi.`);

                 // --- Placeholder for future AJAX call ---
                 /*
                 const currentPrompt = sceneCard.querySelector('#prompt-text-' + sceneNumber)?.innerText || '';
                 const currentNarasi = sceneCard.querySelector('#narasi-text-' + sceneNumber)?.innerText || '';
                 const currentVo = sceneCard.querySelector('#vo-text-' + sceneNumber)?.innerText || '';
                 const characterDetails = document.getElementById('character_details')?.value || ''; // Get original inputs
                 const settingDetails = document.getElementById('setting_details')?.value || '';
                 // ... get other relevant inputs ...

                 console.log("Regenerate requested for:", { sceneNumber, section, currentPrompt, currentNarasi, currentVo });

                 // Show local loading indicator for the section
                 target.disabled = true; // Disable button during fake process

                 // Simulate network request
                 setTimeout(() => {
                     alert("Simulasi regenerate selesai (tidak ada panggilan API sebenarnya).");
                     target.disabled = false; // Re-enable button
                     // In real implementation, update the paragraph text here with response from server
                 }, 1000);
                 */
            }
        });
    }


    // --- Add Scene Button Logic ---
     const addSceneBtn = document.getElementById('addSceneBtn');
     const structuredOutputContainer = document.getElementById('structuredOutputContainer');

     if (addSceneBtn && structuredOutputContainer) {
         addSceneBtn.addEventListener('click', function() {
             const existingCards = structuredOutputContainer.querySelectorAll('.scene-card');
             const nextSceneNumber = existingCards.length + 1;
             const newCardHTML = createNewSceneCardHTML(nextSceneNumber);
             structuredOutputContainer.insertAdjacentHTML('beforeend', newCardHTML);
             // Optional: Scroll to the new card
             const newCardElement = document.getElementById(`scene-card-${nextSceneNumber}`);
             if (newCardElement) {
                 newCardElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
             }
            // Renumbering might not be strictly needed if always adding to end,
            // but calling it ensures absolute consistency if delete was used before add.
             // renumberScenes();
         });
     }


    // --- Export Logic (Modified getStructuredData to read editable fields) ---

    function downloadFile(content, filename, contentType) {
        const blob = new Blob([content], { type: contentType });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click();
        document.body.removeChild(a); URL.revokeObjectURL(url);
    }

    function getStructuredData() {
        const scenes = [];
        const container = document.getElementById('structuredOutputContainer');
        if (!container) return null;
        const sceneCards = container.querySelectorAll('.scene-card');

        sceneCards.forEach((card) => {
             const sceneNumber = parseInt(card.dataset.sceneNumber || 0, 10); // Get number from data attribute
             if (sceneNumber === 0) return; // Skip if somehow number is invalid

            const sceneData = {
                scene_number: sceneNumber,
                title: card.querySelector('.scene-title')?.innerText.replace(`Adegan ${sceneNumber}:`, '').trim() || `Adegan ${sceneNumber}`, // Read current title
                prompt_gambar: card.querySelector(`#prompt-text-${sceneNumber}`)?.innerText || '', // Read CURRENT text
                narasi: card.querySelector(`#narasi-text-${sceneNumber}`)?.innerText || '',
                voice_over: card.querySelector(`#vo-text-${sceneNumber}`)?.innerText || ''
            };
            scenes.push(sceneData);
        });
        // Ensure scenes are sorted by scene_number before returning, in case reordering was implemented
        scenes.sort((a, b) => a.scene_number - b.scene_number);
        return scenes.length > 0 ? scenes : null;
    }

    // Export Button Event Listeners (TXT, CSV, JSON) - No change needed from previous version
    // Salin event listener untuk #exportTxtBtn, #exportCsvBtn, #exportJsonBtn dari kode sebelumnya ke sini
    const exportTxtBtn = document.getElementById('exportTxtBtn');
    const exportCsvBtn = document.getElementById('exportCsvBtn');
    const exportJsonBtn = document.getElementById('exportJsonBtn');

    if (exportTxtBtn) {
        exportTxtBtn.addEventListener('click', function() {
            const scenesData = getStructuredData();
            if (!scenesData) { alert('Tidak ada data cerita terstruktur untuk diekspor.'); return; }
            let txtContent = "Rencana Konten Cerita\n=========================\n\n";
            scenesData.forEach(scene => {
                txtContent += `### Adegan ${scene.scene_number}: ${scene.title}\n\n`;
                txtContent += "**Prompt Gambar:**\n" + (scene.prompt_gambar || 'N/A') + "\n\n";
                txtContent += "**Narasi Cerita:**\n" + (scene.narasi || 'N/A') + "\n\n";
                txtContent += "**Teks Voice Over:**\n" + (scene.voice_over || 'N/A') + "\n\n";
                txtContent += "-------------------------\n\n";
            });
            downloadFile(txtContent, 'story_plan.txt', 'text/plain;charset=utf-8');
        });
    }
    if (exportCsvBtn) {
        exportCsvBtn.addEventListener('click', function() {
            const scenesData = getStructuredData();
            if (!scenesData) { alert('Tidak ada data cerita terstruktur untuk diekspor.'); return; }
            const escapeCsvField = (field) => {
                const stringField = String(field ?? '');
                if (stringField.includes('"') || stringField.includes(',') || stringField.includes('\n')) {
                    return `"${stringField.replace(/"/g, '""')}"`;
                }
                return stringField;
            };
            const headers = ["Scene Number", "Title", "Image Prompt", "Narration", "Voice Over"];
            let csvContent = headers.map(escapeCsvField).join(',') + '\n';
            scenesData.forEach(scene => {
                const row = [scene.scene_number, scene.title, scene.prompt_gambar, scene.narasi, scene.voice_over];
                csvContent += row.map(escapeCsvField).join(',') + '\n';
            });
            const bom = "\uFEFF";
            downloadFile(bom + csvContent, 'story_plan.csv', 'text/csv;charset=utf-8');
        });
    }
     if (exportJsonBtn) {
        exportJsonBtn.addEventListener('click', function() {
            const scenesData = getStructuredData();
            if (!scenesData) { alert('Tidak ada data cerita terstruktur untuk diekspor.'); return; }
            const jsonContent = JSON.stringify(scenesData, null, 2);
            downloadFile(jsonContent, 'story_plan.json', 'application/json;charset=utf-8');
        });
    }
</script>

</body>
</html>