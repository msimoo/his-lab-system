<?php
include('config/config.php');

if (isset($_GET['api_request']) && $_GET['api_request'] == 'get_queue') {
    header('Content-Type: application/json');
    date_default_timezone_set('Africa/Khartoum');
    $today = date('Y-m-d');
    $response = ['calling' => null, 'next_list' => [], 'inside_list' => []];
    
    // جلب المريض الذي يتم النداء عليه الآن
    $call_stmt = $mysqli->query("SELECT a.app_id, a.ticket_number, c.clinic_name, p.name AS patient_name FROM rpos_appointments a JOIN rpos_clinics c ON a.clinic_id = c.clinic_id JOIN rpos_patients p ON a.patient_id = p.patient_id WHERE a.status = 'Calling' AND a.appointment_date = '$today' ORDER BY a.app_id DESC LIMIT 1");
    if($call_stmt && $call_stmt->num_rows > 0) {
        $c = $call_stmt->fetch_assoc();
        $repeatToken = null;
        $repeatFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'waiting_screen_repeat_' . intval($c['app_id']) . '.json';
        if (file_exists($repeatFile)) {
            $repeatData = json_decode(file_get_contents($repeatFile), true);
            if (is_array($repeatData) && intval($repeatData['app_id']) === intval($c['app_id'])) {
                $repeatToken = $repeatData['timestamp'] ?? time();
            }
            @unlink($repeatFile);
        }

        $response['calling'] = [
            'app_id' => $c['app_id'],
            'ticket' => str_pad($c['ticket_number'], 3, '0', STR_PAD_LEFT),
            'clinic' => $c['clinic_name'],
            'patient' => $c['patient_name'],
            'repeat' => $repeatToken !== null,
            'repeat_token' => $repeatToken,
        ];
    }
    
    // جلب المنتظرين مصنفين بالعيادة (أول 5 فقط)
    $next_stmt = $mysqli->query("SELECT a.ticket_number, c.clinic_name, p.name AS patient_name FROM rpos_appointments a JOIN rpos_clinics c ON a.clinic_id = c.clinic_id JOIN rpos_patients p ON a.patient_id = p.patient_id WHERE a.status = 'Pending' AND a.appointment_date = '$today' ORDER BY a.ticket_number ASC LIMIT 6");
    while($n = $next_stmt->fetch_assoc()){
        $response['next_list'][] = ['ticket' => str_pad($n['ticket_number'], 3, '0', STR_PAD_LEFT), 'clinic' => $n['clinic_name'], 'patient' => $n['patient_name']];
    }

    // جلب المتواجدين بالداخل بالعيادات
    $in_stmt = $mysqli->query("SELECT a.ticket_number, c.clinic_name, p.name AS patient_name FROM rpos_appointments a JOIN rpos_clinics c ON a.clinic_id = c.clinic_id JOIN rpos_patients p ON a.patient_id = p.patient_id WHERE a.status = 'In Consultation' AND a.appointment_date = '$today' LIMIT 6");
    while($in = $in_stmt->fetch_assoc()){
        $response['inside_list'][] = ['ticket' => str_pad($in['ticket_number'], 3, '0', STR_PAD_LEFT), 'clinic' => $in['clinic_name'], 'patient' => $in['patient_name']];
    }
    
    echo json_encode($response); exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>شاشة طابور الانتظار</title>
    <style>
        body { font-family: 'Tajawal', sans-serif; background-color: #0b0f19; margin: 0; height: 100vh; display: flex; flex-direction: column; overflow: hidden; color: white; }
        
        .header { background: linear-gradient(135deg, #1a2235 0%, #0f1724 100%); padding: 20px 40px; display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid #2dce89; box-shadow: 0 5px 20px rgba(0,0,0,0.5); }
        .header h1 { margin: 0; font-size: 2.2rem; color: #fff; font-weight: 900; letter-spacing: 1px; text-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .header .time-box { font-size: 1.8rem; color: #11cdef; font-weight: bold; font-family: 'Courier New', monospace; }
        
        /* Voice status indicator */
        #voiceStatus {
            position: fixed;
            top: 80px;
            left: 20px;
            background: rgba(17, 205, 239, 0.15);
            border: 1px solid rgba(17, 205, 239, 0.3);
            border-radius: 12px;
            padding: 8px 16px;
            font-size: 0.7rem;
            color: #7ecfdf;
            z-index: 100;
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            gap: 8px;
            pointer-events: none;
        }
        #voiceStatus .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        #voiceStatus .status-dot.active { background: #2dce89; box-shadow: 0 0 6px rgba(45,206,137,0.5); }
        #voiceStatus .status-dot.inactive { background: #f5365c; }
        #voiceStatus .status-dot.loading { background: #fb6340; animation: pulse 1s infinite; }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
        
        .main-content { display: flex; flex: 1; padding: 20px; gap: 20px; }
        
        /* القسم الأيمن: النداء الحالي */
        .calling-section { flex: 1.2; background: linear-gradient(145deg, #1d2b4a 0%, #0a1122 100%); border-radius: 20px; border: 2px solid #11cdef; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; box-shadow: 0 0 30px rgba(17, 205, 239, 0.15); transition: 0.3s; position: relative; overflow: hidden; }
        .calling-section.active-pulse { animation: alertPulse 1.5s infinite; border-color: #f5365c; background: linear-gradient(145deg, #4a1d24 0%, #220a0d 100%); box-shadow: 0 0 50px rgba(245, 54, 92, 0.4); }
        
        .call-title { font-size: 3rem; color: #a0aec0; margin-bottom: 10px; }
        .active-pulse .call-title { color: #fcc; }
        
        .ticket-number { font-size: 12rem; font-weight: 900; line-height: 1; color: #11cdef; text-shadow: 0 10px 20px rgba(0,0,0,0.5); margin-bottom: 10px; }
        .active-pulse .ticket-number { color: #fff; text-shadow: 0 0 20px #f5365c; }

        .patient-name { font-size: 2.4rem; color: #a0aec0; margin-bottom: 20px; }
        .active-pulse .patient-name { color: #ffd97d; }
        
        .clinic-name { font-size: 4rem; color: #fff; font-weight: 700; background: rgba(255,255,255,0.1); padding: 15px 40px; border-radius: 50px; }
        
        /* القسم الأيسر: مقسوم لنصفين (الانتظار وبالداخل) */
        .side-lists { flex: 0.8; display: flex; flex-direction: column; gap: 20px; }
        .list-box { flex: 1; background: #1a2235; border-radius: 20px; padding: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); overflow: hidden;}
        
        .list-title { font-size: 1.8rem; color: #fff; border-bottom: 2px solid #5e72e4; padding-bottom: 10px; margin-bottom: 15px; text-align: center; font-weight: bold; }
        .list-title.green-line { border-bottom-color: #2dce89; }
        
        .ticket-item { display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.05); padding: 12px 20px; margin-bottom: 10px; border-radius: 12px; font-size: 1.5rem; font-weight: bold; border-right: 4px solid #11cdef; }
        .inside-item { border-right-color: #2dce89; background: rgba(45, 206, 137, 0.1); }
        .ticket-item span.t-num { color: #fff; font-size: 1.8rem; }
        .ticket-item span.c-name { color: #a0aec0; }

        @keyframes alertPulse { 0% { transform: scale(1); } 50% { transform: scale(1.02); } 100% { transform: scale(1); } }
        
        /* زر تفعيل الصوت (مهم) */
        #initOverlay { position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); z-index:9999; display: flex; justify-content: center; align-items: center; }
        #initOverlay button { padding: 20px 40px; font-size: 2rem; font-family: 'Tajawal'; border-radius: 15px; border:none; background: #2dce89; color: white; cursor: pointer; font-weight: bold; box-shadow: 0 10px 20px rgba(45, 206, 137, 0.4); }
    </style>
</head>
<body>
    <audio id="chimeSound" src="assets/call_ring.mp3" preload="auto"></audio>
    <audio id="chimeFallback" preload="none"></audio>

    <div id="initOverlay">
        <div style="text-align:center;">
            <i class="fas fa-tv" style="font-size:4rem;display:block;margin-bottom:20px;color:#2dce89;"></i>
            <button onclick="startSystem()" style="padding:20px 40px;font-size:2rem;font-family:'Tajawal';border-radius:15px;border:none;background:#2dce89;color:white;cursor:pointer;font-weight:bold;box-shadow:0 10px 20px rgba(45,206,137,0.4);">
                <i class="fas fa-play-circle"></i> إضغط هنا لتفعيل الشاشة والنظام الصوتي
            </button>
            <p style="color:#a0aec0;margin-top:20px;font-size:1.2rem;">
                <i class="fas fa-volume-up"></i> سيتم تفعيل النظام الصوتي للإعلان عن المرضى
            </p>
        </div>
    </div>

    <!-- مؤشر حالة الصوت -->
    <div id="voiceStatus">
        <span class="status-dot loading"></span>
        <span class="voice-label">جاري تحميل الأصوات...</span>
    </div>

    <div class="header">
        <h1>شاشة طابور المرضى</h1>
        <div class="time-box" id="clock">00:00:00</div>
    </div>

    <div class="main-content">
        <div class="calling-section" id="calling_panel">
            <div class="call-title">تفضل بالدخول</div>
            <div class="ticket-number" id="current_ticket">---</div>
            <div class="patient-name" id="current_patient">---</div>
            <div class="clinic-name" id="current_clinic">في انتظار النداء...</div>
        </div>

        <div class="side-lists">
            <div class="list-box">
                <div class="list-title">المرضى بالداخل <span style="color:#2dce89;">●</span></div>
                <div id="inside_list"></div>
            </div>
            
            <div class="list-box">
                <div class="list-title green-line">التالي في الانتظار ⏳</div>
                <div id="next_list"></div>
            </div>
        </div>
    </div>

    <script src="assets/js/jquery.js"></script>
    <script>
        let lastCalledAppId = null;
        let lastRepeatToken = null;
        let isSystemActive = false;

        // تحديث الساعة
        setInterval(() => {
            let d = new Date();
            document.getElementById('clock').innerText = d.toLocaleTimeString('ar-EG', {hour: '2-digit', minute:'2-digit', second:'2-digit'});
        }, 1000);

        // ============================================
        // Voice Engine - Load available voices
        // ============================================
        var _arabicVoice = null;
        var _voicesLoaded = false;
        
        function loadVoices() {
            var voices = window.speechSynthesis.getVoices();
            if (voices.length === 0) {
                // Voices not loaded yet, wait for event
                window.speechSynthesis.onvoiceschanged = function() {
                    loadVoices();
                };
                return;
            }
            
            // Filter Arabic voices
            var arabicVoices = [];
            for (var v = 0; v < voices.length; v++) {
                if (voices[v].lang && voices[v].lang.indexOf('ar') === 0) {
                    arabicVoices.push(voices[v]);
                }
            }
            
            // Sort by quality: prefer natural/neural voices, then Microsoft, then Google, then any
            var preferredPatterns = ['natural', 'neural', 'microsoft', 'google', 'saudi', 'egyp', 'female'];
            arabicVoices.sort(function(a, b) {
                var aScore = 0, bScore = 0;
                var aName = a.name.toLowerCase();
                var bName = b.name.toLowerCase();
                for (var p = 0; p < preferredPatterns.length; p++) {
                    if (aName.indexOf(preferredPatterns[p]) !== -1) aScore += (preferredPatterns.length - p);
                    if (bName.indexOf(preferredPatterns[p]) !== -1) bScore += (preferredPatterns.length - p);
                }
                return bScore - aScore;
            });
            
            _arabicVoice = arabicVoices.length > 0 ? arabicVoices[0] : null;
            _voicesLoaded = true;
            
            // Update voice status indicator
            updateVoiceStatus();
            
            // Debug info
            console.log('Total voices:', voices.length);
            console.log('Arabic voices:', arabicVoices.length);
            if (_arabicVoice) {
                console.log('Selected voice:', _arabicVoice.name, _arabicVoice.lang);
            }
        }
        
        function updateVoiceStatus() {
            var el = document.getElementById('voiceStatus');
            if (!el) return;
            var dot = el.querySelector('.status-dot');
            var label = el.querySelector('.voice-label');
            if (!_voicesLoaded) {
                dot.className = 'status-dot loading';
                label.textContent = 'جاري تحميل الأصوات...';
            } else if (_arabicVoice) {
                dot.className = 'status-dot active';
                label.textContent = '✓ ' + _arabicVoice.name;
            } else {
                dot.className = 'status-dot inactive';
                label.textContent = '✗ لا يوجد صوت عربي - استخدام النطق الافتراضي';
            }
        }
        
        function getBestArabicVoice() {
            if (_arabicVoice) return _arabicVoice;
            // Fallback: try to get any Arabic voice
            var voices = window.speechSynthesis.getVoices();
            for (var i = 0; i < voices.length; i++) {
                if (voices[i].lang && voices[i].lang.indexOf('ar') === 0) {
                    return voices[i];
                }
            }
            return null;
        }
        
        function speakText(text, callback) {
            if (!isSystemActive) return;
            
            // Cancel any previous speech
            window.speechSynthesis.cancel();
            
            var msg = new SpeechSynthesisUtterance(text);
            msg.lang = 'ar-SA';
            msg.rate = 0.85;
            msg.pitch = 1.0;
            msg.volume = 1.0;
            
            var voice = getBestArabicVoice();
            if (voice) {
                msg.voice = voice;
            }
            
            if (callback) {
                msg.onend = callback;
            }
            
            window.speechSynthesis.speak(msg);
        }

        function startSystem() {
            document.getElementById('initOverlay').style.display = 'none';
            isSystemActive = true;
            
            // Load voices first
            loadVoices();
            
            // نطق تجريبي لفتح القناة الصوتية للمتصفح
            var testMsg = new SpeechSynthesisUtterance("نظام شاشة الانتظار يعمل بنجاح");
            testMsg.lang = 'ar-SA';
            testMsg.rate = 0.9;
            window.speechSynthesis.speak(testMsg);
            
            fetchQueue();
            setInterval(fetchQueue, 3000);
        }

        // ============================================
        // Web Audio API - Generate chime sound as fallback
        // ============================================
        function playChimeSound() {
            var audioEl = document.getElementById('chimeSound');
            if (audioEl && audioEl.readyState >= 2) {
                audioEl.currentTime = 0;
                audioEl.play().catch(function() { playChimeFallback(); });
            } else {
                playChimeFallback();
            }
        }

        function playChimeFallback() {
            try {
                var audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                var now = audioCtx.currentTime;

                // Three ascending tones (doorbell style)
                [523.25, 659.25, 783.99].forEach(function(freq, i) {
                    var osc = audioCtx.createOscillator();
                    var gain = audioCtx.createGain();
                    osc.type = 'sine';
                    osc.frequency.value = freq;
                    gain.gain.setValueAtTime(0.3, now + i * 0.2);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + i * 0.2 + 0.4);
                    osc.connect(gain);
                    gain.connect(audioCtx.destination);
                    osc.start(now + i * 0.2);
                    osc.stop(now + i * 0.2 + 0.4);
                });
            } catch(e) { /* Audio not supported */ }
        }

        // ============================================
        // محرك النطق الآلي المتطور (TTS Engine v3)
        // ============================================
        function playAnnouncement(ticket, clinic, patient) {
            if (!isSystemActive) return;

            // 1. تشغيل رنة تنبيه (مع fallback صوتي)
            playChimeSound();
            
            // 2. تفعيل المؤثر البصري
            $('#calling_panel').addClass('active-pulse');
            setTimeout(function() { $('#calling_panel').removeClass('active-pulse'); }, 8000);
            
            // 3. تحديث الشاشة فوراً
            $('#current_patient').text(patient);
            $('#current_ticket').text(ticket);
            $('#current_clinic').text(clinic);

            // 4. تحديث مؤشر حالة الصوت
            updateVoiceStatus();

            // 5. تأخير بسيط لنطق الكلام بعد انتهاء الرنة
            setTimeout(function() {
                try {
                    // ===== تكوين جملة طبيعية جداً مع فواصل =====
                    // الجملة الأولى: الإعلان الرئيسي
                    var textToSpeak = 'الرجاء من المريض ' + patient + ' حامل التذكرة رقم ' + ticket + ' التفضل بالدخول إلى ' + clinic;
                    
                    // نطق الجملة
                    speakText(textToSpeak, function() {
                        // تكرار النداء مرة ثانية بعد 4 ثوانٍ من انتهاء الأول
                        setTimeout(function() {
                            speakText(textToSpeak);
                        }, 1000);
                    });
                    
                } catch (e) {
                    console.log('Voice announcement error:', e);
                }
            }, 1200); // 1.2 ثانية تأخير بعد الجرس
        }

        function fetchQueue() {
            $.ajax({
                url: '?api_request=get_queue',
                method: 'GET',
                dataType: 'json',
                success: function(data) {
                    
                    // تحديث النداء الحالي
                    if (data.calling) {
                        $('#current_ticket').text(data.calling.ticket);
                        $('#current_patient').text(data.calling.patient);
                        $('#current_clinic').text(data.calling.clinic);
                        
                        if (lastCalledAppId !== data.calling.app_id) {
                            lastCalledAppId = data.calling.app_id;
                            lastRepeatToken = data.calling.repeat_token || null;
                            playAnnouncement(data.calling.ticket, data.calling.clinic, data.calling.patient);
                        } else if (data.calling.repeat && data.calling.repeat_token && data.calling.repeat_token !== lastRepeatToken) {
                            lastRepeatToken = data.calling.repeat_token;
                            playAnnouncement(data.calling.ticket, data.calling.clinic, data.calling.patient);
                        }
                    } else {
                        $('#current_patient').text('---');
                        $('#current_ticket').text('---');
                        $('#current_clinic').text('في انتظار النداء...');
                    }

                    // تحديث قائمة "بالداخل"
                    let insideHtml = '';
                    data.inside_list.forEach(item => {
                        insideHtml += `<div class="ticket-item inside-item"><span class="t-num">${item.ticket}</span><span class="c-name">${item.clinic}</span></div>`;
                    });
                    $('#inside_list').html(insideHtml || '<div style="text-align:center; color:#555; margin-top:30px;">فارغ</div>');

                    // تحديث قائمة "التالي"
                    let nextHtml = '';
                    data.next_list.forEach(item => {
                        nextHtml += `<div class="ticket-item"><span class="t-num">${item.ticket}</span><span class="c-name">${item.clinic}</span></div>`;
                    });
                    $('#next_list').html(nextHtml || '<div style="text-align:center; color:#555; margin-top:30px;">فارغ</div>');
                }
            });
        }

        // تحميل الأصوات في المتصفح مسبقاً
        window.speechSynthesis.onvoiceschanged = function() { window.speechSynthesis.getVoices(); };
    </script>
</body>
</html>
