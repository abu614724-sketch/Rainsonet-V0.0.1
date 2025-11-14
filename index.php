<?php
// index.php
// Single-file OCR web app (image upload -> text). Uses Tesseract by default.
// If you want to use Google Vision API instead, set $USE_GOOGLE_VISION = true
// IMPORTANT: configure PHP upload limits (post_max_size, upload_max_filesize) as needed.

// ---------- CONFIG ----------
$USE_GOOGLE_VISION = false; // set true to use Google Vision instead of Tesseract
$GOOGLE_VISION_API_KEY = ''; // if using Vision API, put your API key here
$MAX_FILE_BYTES = 6 * 1024 * 1024; // 6 MB max upload
$ALLOWED_MIME = [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/tiff', 'image/bmp',
    // PDF support with Google Vision or Tesseract (needs pdftoppm) - not enabled by default
];
// Path for temporary files
$tempDir = sys_get_temp_dir();
// ---------- END CONFIG ----------

// Helper: safe output
function h($s){ return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

// Perform Google Vision OCR (if opted)
function google_vision_ocr($base64, $api_key){
    $url = "https://vision.googleapis.com/v1/images:annotate?key=" . urlencode($api_key);
    $request = [
        "requests" => [
            [
                "image" => ["content" => $base64],
                "features" => [["type" => "TEXT_DETECTION", "maxResults" => 1]]
            ]
        ]
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request));
    $resp = curl_exec($ch);
    if($resp === false){
        return ['error'=>"Curl error: ".curl_error($ch)];
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    if($code !== 200){
        return ['error'=>"HTTP $code response", 'raw'=>$data];
    }
    if(isset($data['responses'][0]['fullTextAnnotation']['text'])){
        return ['text' => $data['responses'][0]['fullTextAnnotation']['text']];
    } elseif(isset($data['responses'][0]['textAnnotations'][0]['description'])){
        return ['text' => $data['responses'][0]['textAnnotations'][0]['description']];
    } else {
        return ['text' => '']; // no text found
    }
}

// Handle POST (file upload)
$ocrResult = null;
$error = null;
$uploadedPreviewDataUrl = null;
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    if(empty($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE){
        $error = "Koi file upload nahi hui.";
    } else {
        $f = $_FILES['image'];
        if($f['error'] !== UPLOAD_ERR_OK){
            $error = "Upload error code: " . $f['error'];
        } elseif($f['size'] > $MAX_FILE_BYTES){
            $error = "File bohot badi hai. Max ".($MAX_FILE_BYTES/1024/1024)." MB allowed.";
        } else {
            // Validate mime
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $f['tmp_name']);
            finfo_close($finfo);
            if(!in_array($mime, $ALLOWED_MIME)){
                // Try getimagesize fallback
                $imginfo = @getimagesize($f['tmp_name']);
                if(!$imginfo || !in_array($imginfo['mime'], $ALLOWED_MIME)){
                    $error = "Unsupported file type: $mime";
                } else {
                    $mime = $imginfo['mime'];
                }
            }
            if(!$error){
                // Create safe temp file
                $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
                $uniq = bin2hex(random_bytes(8));
                $tmpPath = $tempDir . DIRECTORY_SEPARATOR . "ocr_upload_{$uniq}." . ($ext?:'img');
                if(!move_uploaded_file($f['tmp_name'], $tmpPath)){
                    $error = "Cannot save uploaded file to temporary folder.";
                } else {
                    // For preview (base64)
                    $imgData = file_get_contents($tmpPath);
                    $uploadedPreviewDataUrl = 'data:' . $mime . ';base64,' . base64_encode($imgData);

                    if($USE_GOOGLE_VISION){
                        if(empty($GOOGLE_VISION_API_KEY)){
                            $error = "Google Vision API key not set in config.";
                        } else {
                            $base64 = base64_encode($imgData);
                            $res = google_vision_ocr($base64, $GOOGLE_VISION_API_KEY);
                            if(isset($res['error'])){
                                $error = "Google Vision error: " . (is_string($res['error']) ? $res['error'] : json_encode($res['error']));
                            } else {
                                $ocrResult = $res['text'];
                            }
                        }
                    } else {
                        // Use Tesseract via CLI
                        // Ensure tesseract binary is available and exec is allowed.
                        // Command writes to stdout; capture stderr.
                        // Use English by default; to change, add -l <lang>.
                        $tessBin = 'tesseract'; // assume in PATH
                        // psm 3 is default, you can tune (--psm 6 for single block, etc.)
                        $cmd = escapeshellcmd($tessBin) . ' ' . escapeshellarg($tmpPath) . ' stdout --dpi 300 2>&1';
                        // If you want language specify: ... stdout -l eng --psm 3
                        $out = null;
                        $ret = null;
                        @exec($cmd, $out, $ret);
                        if($ret !== 0){
                            $errText = implode("\n", $out);
                            $error = "Tesseract failed (exit $ret). Make sure tesseract is installed and PHP can run exec().\nTesseract output: " . h($errText);
                        } else {
                            $ocrResult = implode("\n", $out);
                        }
                    }

                    // cleanup temp file
                    @unlink($tmpPath);
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="hi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Simple OCR - Upload document image and copy text</title>
<style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;max-width:900px;margin:24px auto;padding:12px;}
    .card{border:1px solid #ddd;padding:16px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.04);}
    input[type=file]{display:block;margin-top:8px;}
    button{padding:8px 12px;border-radius:6px;border:1px solid #888;background:#f7f7f7;cursor:pointer;}
    pre{white-space:pre-wrap;background:#111;color:#0f0;padding:12px;border-radius:6px;overflow:auto;max-height:50vh;}
    .preview{max-width:100%;height:auto;border:1px solid #ccc;padding:6px;border-radius:6px;margin-top:8px;}
    .row{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;}
    .muted{color:#666;font-size:0.9rem;}
</style>
</head>
<body>
<h2>OCR (Image → Text)</h2>
<div class="card">
    <form method="post" enctype="multipart/form-data" id="ocrForm">
        <label><strong>Upload image / document photo</strong> (JPEG, PNG, WEBP, TIFF, GIF, BMP). Max <?=($MAX_FILE_BYTES/1024/1024)?> MB.</label>
        <input type="file" name="image" id="imageInput" accept="image/*" required>
        <div class="row">
            <button type="submit">Extract Text</button>
            <button type="button" onclick="document.getElementById('imageInput').value='';">Clear</button>
        </div>
        <p class="muted">By default this server will try <strong>Tesseract OCR</strong>. To use Google Vision enable it in the config (server-side).</p>
    </form>

    <?php if($error): ?>
        <div style="margin-top:12px;color:#900;"><strong>Error:</strong> <pre style="display:inline;background:transparent;color:inherit;padding:0;border:none;"><?=h($error)?></pre></div>
    <?php endif; ?>

    <?php if($uploadedPreviewDataUrl): ?>
        <div style="margin-top:12px;">
            <strong>Preview:</strong><br>
            <img src="<?=h($uploadedPreviewDataUrl)?>" alt="preview" class="preview">
        </div>
    <?php endif; ?>

    <?php if(!is_null($ocrResult)): ?>
        <div style="margin-top:12px;">
            <strong>Extracted text:</strong>
            <div style="margin-top:8px;">
                <pre id="ocrText"><?=h($ocrResult === '' ? "[No text found]" : $ocrResult)?></pre>
            </div>
            <div class="row" style="margin-top:8px;">
                <button type="button" onclick="copyText()">Copy to clipboard</button>
                <button type="button" onclick="downloadText()">Download .txt</button>
                <button type="button" onclick="selectText()">Select all</button>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function copyText(){
    const t = document.getElementById('ocrText').innerText;
    if(!t){ alert('Koi text nahi hai.'); return; }
    navigator.clipboard.writeText(t).then(()=>{ alert('Copied to clipboard'); }, (e)=>{ alert('Copy failed: '+e); });
}
function downloadText(){
    const t = document.getElementById('ocrText').innerText;
    if(!t){ alert('Koi text nahi hai.'); return; }
    const blob = new Blob([t], {type:'text/plain'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'ocr-result.txt';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}
function selectText(){
    const range = document.createRange();
    range.selectNode(document.getElementById('ocrText'));
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
}
</script>
</body>
</html>
