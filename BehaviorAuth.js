

class BehaviorAuth {
    constructor (containerId, apiBaseUrl = '/auth/') {
        this.container = document.getElementById (containerId);
        if (!this.container) {
            console.error (' 初始化失败：容器不存在 ');
            return;
        }
        this.container.style.position = 'relative';
        this.container.style.width = '340px';
        this.container.style.height = '46px';
        this.container.style.display = 'inline-block';
        this.apiBaseUrl = apiBaseUrl;
        this.token = '';
        this.salt = '';
        this.difficulty = 0;
        this.segmentKey = '';
        this.seqCounter = 0;
        this.bgBase64 = '';
        this.blockBase64 = '';
        this.blockY = 0;
        this.permEncrypted = '';
        this.gridCols = 0;
        this.gridRows = 0;
        this.pieceW = 0;
        this.pieceH = 0;
        this.isDragging = false;
        this.startX = 0;
        this.currentX = 0;
        this.maxX = 0;
        this.startTime = 0;
        this.trajectory = [];
        this.pauseThreshold = 80;
        this.isVerified = false;
        this.envData = null;
        this.isExpanded = false;
        this.init();
        window.IsPass = false;
    }

    async init() {
        this.renderUI();
        this.bindEvents();
    }

    renderUI() {
        this.container.innerHTML = `
        <style>
            :root {
                --base-width: 340px;
                --btn-height: 46px;
                --panel-height: 288px;
                --transition-speed: 0.4s ease;
            }
            .captcha-root {
                position: absolute;
                left: 0;
                top: 0;
                width: var(--base-width);
                height: var(--btn-height);
                border-radius: 6px;
                overflow: hidden;
                background-color: #4a90d9;
                box-shadow: 0 2px 8px rgba(74, 144, 217, 0.25);
                transition: height var(--transition-speed), background-color 0.3s, box-shadow 0.3s;
                cursor: pointer;
                z-index: 998;
                user-select: none;
            }
            .captcha-root.expand {
                height: var(--panel-height);
                background: #ffffff;
                cursor: default;
                box-shadow: 0 6px 20px rgba(0,0,0,0.12);
            }
            .captcha-root.success {
                height: var(--btn-height);
                background-color: #28a745;
            }
            .bar-text {
                width: 100%;
                height: var(--btn-height);
                line-height: var(--btn-height);
                text-align: center;
                color: #fff;
                font-size: 14px;
                user-select: none;
                position: absolute;
                top: 0;
                left: 0;
                transition: opacity 0.28s ease;
            }
            .captcha-root.expand .bar-text {
                opacity: 0;
                pointer-events: none;
            }
            .auth-card-inner {
                width: var(--base-width);
                box-sizing: border-box;
                padding: 20px;
                position: absolute;
                top: 0;
                left: 0;
                opacity: 0;
                transition: opacity 0.28s ease;
                pointer-events: none;
            }
            .captcha-root.expand .auth-card-inner {
                opacity: 1;
                pointer-events: auto;
            }
            .auth-status {
                text-align: center;
                font-size: 12px;
                margin-bottom: 10px;
                height: 16px;
                color: #6c757d;
                transition: color 0.3s;
            }
            .auth-status.success { color: #28a745; font-weight: bold; }
            .auth-status.fail { color: #dc3545; }
            .auth-status.loading { color: #6c757d; }
            .puzzle-area {
                width: 300px;
                height: 150px;
                margin: 0 auto;
                position: relative;
            }
            .loading-animation {
                display: flex;
                gap: 8px;
                justify-content: center;
                align-items: center;
                height: 150px;
                width: 300px;
                background: #f8f9fa;
                border: 1px solid #ddd;
                border-radius: 4px;
                box-sizing: border-box;
            }
            .loading-block {
                width: 14px;
                height: 14px;
                background: #4a90d9;
                border-radius: 2px;
                animation: blockPulse 1.4s infinite ease-in-out both;
            }
            .loading-block:nth-child(1) { animation-delay: 0s; }
            .loading-block:nth-child(2) { animation-delay: 0.3s; }
            .loading-block:nth-child(3) { animation-delay: 0.5s; }
            @keyframes blockPulse {
                0%, 80%, 100% { transform: scale(0.5); opacity: 0.3; }
                40% { transform: scale(1); opacity: 1; }
            }
            .puzzle-container {
                position: absolute;
                top: 0;
                left: 0;
                width: 300px;
                height: 150px;
                border-radius: 4px;
                overflow: hidden;
                background: #eee;
                border: 1px solid #ddd;
                transition: opacity 0.3s;
                box-sizing: border-box;
            }
            .puzzle-bg { width: 100%; height: 100%; display: block; }
            .puzzle-block {
                position: absolute;
                top: 0;
                left: 0;
                height: 44px;
                width: 44px;
                display: block;
                filter: drop-shadow(2px 2px 4px rgba(0,0,0,0.4));
                transition: filter 0.2s;
                z-index: 10;
            }
            .slider-track {
                width: 300px;
                height: 40px;
                background: #f5f5f5;
                border-radius: 6px;
                margin: 15px auto 0;
                position: relative;
                border: 1px solid #ddd;
                overflow: hidden;
                transition: background 0.3s, border-color 0.3s;
            }
            .slider-track-text {
                position: absolute;
                width: 100%;
                text-align: center;
                line-height: 40px;
                font-size: 13px;
                color: #999;
                pointer-events: none;
                z-index: 1;
                transition: color 0.3s;
                user-select: none;
            }
            .slider-btn {
                width: 40px;
                height: 40px;
                background: #fff;
                border-radius: 6px;
                position: absolute;
                top: 0px;
                left: 0px;
                cursor: pointer;
                z-index: 2;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                display: flex;
                align-items: center;
                justify-content: center;
                transition: box-shadow 0.2s, background 0.3s;
            }
            .slider-btn::after { content: '►'; color: #666; font-size: 14px; transition: color 0.3s; }
            .slider-btn:active { box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
            .slider-btn.success { background: #28a745; }
            .slider-btn.success::after { color: #fff; }
            .slider-btn.fail { background: #dc3545; }
            .slider-btn.fail::after { color: #fff; }
            .auth-brand {
                position: absolute;
                bottom: -4px;
                right: 12px;
                font-size: 15px;
                color: #ccc;
                pointer-events: none;
                letter-spacing: 0.5px;
            }
            /* ========== 新增左下角按钮样式 ========== */
            .captcha-action-buttons {
    position: absolute;
    left: 20px;
    bottom: -8px;
    display: flex;
    gap: 10px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.28s ease;
}
            .captcha-root.expand .captcha-action-buttons {
                opacity: 1;
                pointer-events: auto;
            }
            .captcha-action-btn {
                width: 26px;
                height: 26px;
                border-radius: 4px;
                border: 1px solid #ddd;
                background: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                color: #666;
                font-size: 14px;
                transition: all 0.2s;
            }
            .captcha-action-btn:hover {
                background: #f0f0f0;
                color: #333;
            }
            .captcha-refresh-btn svg,
            .captcha-close-btn svg {
                display: block;
            }
        </style>
        <div class="captcha-root" id="rootWrap">
            <div class="bar-text" id="tipTxt">点击完成滑块验证</div>
            <div class="auth-card-inner">
                <div class="auth-status loading" id="auth-status">加载中</div>
                <div class="puzzle-area">
                    <div class="loading-animation" id="loading-animation">
                        <div class="loading-block"></div>
                        <div class="loading-block"></div>
                        <div class="loading-block"></div>
                    </div>
                    <div class="puzzle-container" id="puzzle-container" style="display:none;">
                        <img class="puzzle-bg" id="puzzle-bg" src="" alt="">
                        <img class="puzzle-block" id="puzzle-block" src="" alt="">
                    </div>
                </div>
                <div class="slider-track" id="slider-track">
                    <div class="slider-track-text" id="slider-track-text">拖动滑块完成验证</div>
                    <div class="slider-btn" id="slider-btn"></div>
                </div>
                <div class="auth-brand">Dino Captcha</div>
                <!-- 新增左下角按钮DOM -->
                <div class="captcha-action-buttons">
                    <div class="captcha-action-btn captcha-refresh-btn" id="btn-refresh">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
                    </div>
                    <div class="captcha-action-btn captcha-close-btn" id="btn-close">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </div>
                </div>
            </div>
        </div>
        `;
        this.rootWrap = document.getElementById('rootWrap');
        this.tipTxt = document.getElementById('tipTxt');
        this.statusEl = document.getElementById('auth-status');
        this.loadingAnimation = document.getElementById('loading-animation');
        this.puzzleContainer = document.getElementById('puzzle-container');
        this.puzzleBg = document.getElementById('puzzle-bg');
        this.puzzleBlock = document.getElementById('puzzle-block');
        this.sliderTrack = document.getElementById('slider-track');
        this.sliderTrackText = document.getElementById('slider-track-text');
        this.sliderBtn = document.getElementById('slider-btn');
        // 绑定新增按钮DOM
        this.btnRefresh = document.getElementById('btn-refresh');
        this.btnClose = document.getElementById('btn-close');
    }

    bindEvents() {
        this.rootWrap.addEventListener('click', () => {
            if (this.isExpanded || this.isVerified) return;
            this.expand();
        });
        this.sliderBtn.addEventListener('mousedown', (e) => this.onDragStart(e.clientX, e.clientY));
        document.addEventListener('mousemove', (e) => this.onDragMove(e.clientX, e.clientY));
        document.addEventListener('mouseup', () => this.onDragEnd());
        this.sliderBtn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            this.onDragStart(e.touches[0].clientX, e.touches[0].clientY);
        });
        document.addEventListener('touchmove', (e) => this.onDragMove(e.touches[0].clientX, e.touches[0].clientY));
        document.addEventListener('touchend', () => this.onDragEnd());

        // ========== 新增按钮点击事件 ==========
        // 刷新按钮：重置验证码
        this.btnRefresh.addEventListener('click', (e) => {
            e.stopPropagation();
            this.reset();
        });
        // 关闭按钮：收起弹窗，重置状态
        this.btnClose.addEventListener('click', (e) => {
            e.stopPropagation();
            this.closePanel();
        });
    }

    // 新增关闭面板方法
    closePanel() {
        this.isExpanded = false;
        this.rootWrap.classList.remove('expand');
        // 恢复初始文字与状态
        this.tipTxt.innerText = '点击完成滑块验证';
        this.isVerified = false;
        // 重置滑块位置
        this.sliderBtn.style.left = '0px';
        this.puzzleBlock.style.left = '0px';
    }

    expand() {
        this.isExpanded = true;
        this.rootWrap.classList.add('expand');
        this.startAuthProcess();
    }

    shrink () {
        this.isExpanded = false;
        this.rootWrap.classList.remove ('expand');
        this.rootWrap.classList.add ('success');
        this.tipTxt.innerText = ' 验证已通过 ';
    }

    async startAuthProcess () {
        try {
            if (typeof hashwasm === 'undefined') throw new Error ('WASM 引擎未加载 ');
            if (typeof CryptoJS === 'undefined') throw new Error ('CryptoJS 未加载 ');
            this.setStatus ('loading', ' 加载中 ');
            this.envData = await this.collectEnvironment ();
            const initData = await this.api('init');
            this.token = initData.token;
            this.salt = initData.salt;
            this.difficulty = initData.difficulty;
            this.segmentKey = initData.segment_key;
            const nonce = await this.solvePOW(this.salt, this.difficulty);
            await this.segmentedApi('verify-pow', { nonce: String(nonce) });
            const puzzleData = await this.segmentedApi('get-puzzle', {});
            
            this.bgBase64       = puzzleData.bg_base64;
            this.blockBase64    = puzzleData.block_base64;
            this.blockY         = puzzleData.block_y;
            this.permEncrypted  = puzzleData.perm_encrypted;
            this.gridCols       = puzzleData.grid_cols;
            this.gridRows       = puzzleData.grid_rows;
            this.pieceW         = puzzleData.piece_w;
            this.pieceH         = puzzleData.piece_h;

            // === 解密打乱数组并用 Canvas 还原图片 ===
            if (this.permEncrypted) {
                const perm = this.decryptPermutation(this.permEncrypted, this.token);
                this.bgBase64 = await this.reconstructImage(
                    this.bgBase64, perm,
                    this.gridCols, this.gridRows,
                    this.pieceW, this.pieceH
                );
            }

            this.renderPuzzle ();
        } catch (err) {
            this.setStatus ('fail', err.message || ' 验证初始化失败 ');
            console.error (err);
        }
    }

    decryptPermutation(encryptedPerm, token) {
        const tokenHash = CryptoJS.SHA256(token).toString().substring(0, 32);
        const key = CryptoJS.enc.Utf8.parse(tokenHash);
        const rawData = CryptoJS.enc.Base64.parse(encryptedPerm);
        const iv = CryptoJS.lib.WordArray.create(rawData.words.slice(0, 4), 16);
        const ciphertext = CryptoJS.lib.WordArray.create(rawData.words.slice(4), rawData.sigBytes - 16);
        const decrypted = CryptoJS.AES.decrypt(
            CryptoJS.lib.CipherParams.create({ ciphertext: ciphertext }),
            key,
            { iv: iv, mode: CryptoJS.mode.CBC, padding: CryptoJS.pad.Pkcs7 }
        );
        const jsonStr = decrypted.toString(CryptoJS.enc.Utf8);
        return JSON.parse(jsonStr);
    }

    reconstructImage(shuffledBase64, perm, cols, rows, pieceW, pieceH) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                canvas.width  = cols * pieceW;
                canvas.height = rows * pieceH;
                const ctx = canvas.getContext('2d');
                for (let i = 0; i < perm.length; i++) {
                    const origIndex = perm[i];
                    const origCol   = origIndex % cols;
                    const origRow   = Math.floor(origIndex / cols);
                    const shufCol   = i % cols;
                    const shufRow   = Math.floor(i / cols);
                    ctx.drawImage(
                        img,
                        shufCol * pieceW, shufRow * pieceH, pieceW, pieceH,
                        origCol * pieceW, origRow * pieceH, pieceW, pieceH
                    );
                }
                const dataUrl = canvas.toDataURL('image/png');
                resolve(dataUrl.split(',')[1]);
            };
            img.onerror = () => reject(new Error('图片还原失败'));
            img.src = 'data:image/png;base64,' + shuffledBase64;
        });
    }

    renderPuzzle () {
        this.loadingAnimation.style.display = 'none';
        this.puzzleBg.src = 'data:image/png;base64,' + this.bgBase64;
        this.puzzleBlock.src = 'data:image/png;base64,' + this.blockBase64;
        this.puzzleBlock.style.top = this.blockY + 'px';
        this.puzzleBlock.style.left = '0px';
        this.puzzleBlock.style.display = 'block';
        this.puzzleContainer.style.display = 'block';
        this.setStatus ('', ' 请完成验证 ');
        this.maxX = this.sliderTrack.offsetWidth - this.sliderBtn.offsetWidth;
    }

    onDragStart(clientX, clientY) {
        if (!this.blockBase64 || this.isDragging || this.isVerified) return;
        this.isDragging = true;
        this.startX = clientX;
        this.startTime = Date.now();
        this.trajectory = [];
        this.recordMousePosition(clientX, clientY);
    }

    onDragMove(clientX, clientY) {
        if (!this.isDragging) return;
        const deltaX = clientX - this.startX;
        this.currentX = Math.max(0, Math.min(deltaX, this.maxX));
        this.sliderBtn.style.left = this.currentX + 'px';
        this.puzzleBlock.style.left = this.currentX + 'px';
        this.recordMousePosition(clientX, clientY);
    }

    recordMousePosition(clientX, clientY) {
        const rect = this.puzzleContainer.getBoundingClientRect();
        this.trajectory.push({
            x: clientX - rect.left,
            y: clientY - rect.top,
            t: Date.now() - this.startTime
        });
    }

    async onDragEnd () {
        if (!this.isDragging) return;
        this.isDragging = false;
        if (this.currentX === 0) return;
        this.setStatus ('loading', ' 验证中 ');
        const behaviorData = this.analyzeBehavior();
        const payload = { behavior: behaviorData, env: this.envData };
        const encryptedData = await this.encryptBehaviorData(payload, this.token);
        try {
            const result = await this.segmentedApi('verify-final', {
                offset_x: Math.round(this.currentX),
                behavior_data: encryptedData
            });
            this.isVerified = true;
            this.setStatus ('success', ' 验证成功 ');
            this.sliderBtn.classList.add ('success');
            this.puzzleContainer.style.opacity = '0';
            setTimeout(() => {
                this.shrink();
            }, 580);
            this.sliderBtn.style.left = this.maxX + 'px';
            this.sliderTrack.style.background = '#e8f5e9';
            this.sliderTrack.style.borderColor = '#28a745';
            if (this.sliderTrackText) {
                this.sliderTrackText.innerText = ' 验证成功 ';
                this.sliderTrackText.style.color = '#28a745';
                this.sliderTrackText.style.fontWeight = 'bold';
                window.IsPass = true;
            }
            if (this.onSuccess && typeof this.onSuccess === 'function') {
                this.onSuccess(result.token);
            }
        } catch (err) {
            this.setStatus ('fail', err.message || ' 验证失败 ');
            this.sliderBtn.classList.add ('fail');
            this.puzzleBlock.style.filter = 'drop-shadow (2px 2px 4px rgba (220,53,69,0.8))';
            if (this.onFail && typeof this.onFail === 'function') {
                this.onFail(err.message || '验证失败');
            }
            setTimeout (() => this.reset (), 2000);
        }
    }

    analyzeBehavior() {
        const duration = Date.now() - this.startTime;
        let pauseCount = 0, totalPauseTime = 0, speeds = [];
        for (let i = 1; i < this.trajectory.length; i++) {
            const prev = this.trajectory[i - 1];
            const curr = this.trajectory[i];
            const dt = curr.t - prev.t;
            const dx = Math.abs(curr.x - prev.x);
            const dy = Math.abs(curr.y - prev.y);
            const dist = Math.sqrt(dx * dx + dy * dy);
            if (dt > 0) speeds.push(dist / dt);
            if (dt > this.pauseThreshold && dist < 2) {
                pauseCount++;
                totalPauseTime += dt;
            }
        }
        const avgSpeed = speeds.length ? speeds.reduce((a, b) => a + b, 0) / speeds.length : 0;
        const speedVariance = speeds.length ? speeds.reduce((acc, s) => acc + Math.pow(s - avgSpeed, 2), 0) / speeds.length : 0;
        return { duration, pause_count: pauseCount, total_pause_time: totalPauseTime, speed_variance: isNaN(speedVariance) ? 0 : speedVariance };
    }

    async encryptBehaviorData(data, token) {
        const key = CryptoJS.SHA256(token);
        const iv = CryptoJS.lib.WordArray.random(16);
        const encrypted = CryptoJS.AES.encrypt(JSON.stringify(data), key, { iv: iv, mode: CryptoJS.mode.CBC, padding: CryptoJS.pad.Pkcs7 });
        return iv.toString() + encrypted.toString();
    }

    async segmentEncrypt(data, keyStr) {
        const key = CryptoJS.SHA256(keyStr);
        const iv = CryptoJS.lib.WordArray.random(16);
        const encrypted = CryptoJS.AES.encrypt(data, key, { iv: iv, mode: CryptoJS.mode.CBC, padding: CryptoJS.pad.Pkcs7 });
        return iv.toString() + encrypted.toString();
    }

    async segmentDecrypt(data, keyStr) {
        const key = CryptoJS.SHA256(keyStr);
        const iv = CryptoJS.enc.Hex.parse(data.substring(0, 32));
        const ciphertext = data.substring(32);
        const decrypted = CryptoJS.AES.decrypt(ciphertext, key, { iv: iv, mode: CryptoJS.mode.CBC, padding: CryptoJS.pad.Pkcs7 });
        return decrypted.toString(CryptoJS.enc.Utf8);
    }

    async segmentedApi (action, requestData) {
        const seq = String (++this.seqCounter);
        const reqJson = JSON.stringify (requestData);
        const encryptedReq = await this.segmentEncrypt (reqJson, this.segmentKey);
        const chunkSize = Math.ceil (encryptedReq.length/ 5);
        for (let i = 0; i < 5; i++) {
            const chunk = encryptedReq.substring (i * chunkSize, (i + 1) * chunkSize);
            await this.api ('send-segment', null, { token: this.token, seq: seq, index: i, data: chunk });
        }
        await this.api ('execute', null, { token: this.token, seq: seq, action: action });
        let encryptedRes = '';
        for (let i = 0; i < 5; i++) {
            const res = await this.api ('fetch-segment', { token: this.token, seq: seq, index: i });
            encryptedRes += res.data;
        }
        const json = await this.segmentDecrypt (encryptedRes, this.segmentKey);
        const result = JSON.parse (json);
        if (result.code !== 200) throw new Error (result.msg || ' 请求失败 ');
        return result;
    }

    async solvePOW(salt, difficulty) {
        const prefix = '0'.repeat(difficulty);
        let nonce = 0;
        while (true) {
            for (let i = 0; i < 5000; i++) {
                if ((await hashwasm.sha256(salt + nonce)).startsWith(prefix)) return nonce;
                nonce++;
            }
            await new Promise(r => setTimeout(r, 0));
        }
    }

    async api (action, params = null, postData = null) {
        let url = this.apiBaseUrl + '?action=' + action;
        if (params) {
            const qs = new URLSearchParams ();
            for (const [k, v] of Object.entries (params)) qs.set (k, String (v));
            url += '&' + qs.toString ();
        }
        const opt = { method: 'GET', headers: { 'Content-Type': 'application/json' } };
        if (postData) {
            opt.method = 'POST';
            opt.body = JSON.stringify (postData);
        }
        const resp = await fetch (url, opt);
        const txt = await resp.text ();
        let json;
        try { json = JSON.parse (txt); } catch { throw new Error (' 返回数据异常 '); }
        if (resp.status !== 200 || json.code !== 200) throw new Error (json.msg || ' 接口请求失败 ');
        return json;
    }

    async collectEnvironment() {
        const env = {};
        try {
            env.webdriver = navigator.webdriver === true;
            env.auto_phantom = !!(window._phantom || window.callPhantom);
            env.auto_nightmare = !!window.__nightmare; // [DEBUG] 移除 window.electron 误伤(Trae/Electron 浏览器)
            env.auto_cdc = this.detectCDC();
            env.auto_selenium = !!(window._selenium || window.__selenium_evaluate || window.__webdriver_evaluate || window.__driver_evaluate);
            env.auto_puppeteer = !!(window.__puppeteer || window.__nightmare);
            env.proto_tampered = this.detectProtoTamper();
            env.perm_inconsistency = false;
            env.win_w = window.innerWidth || 0;
            env.scr_w = screen.width || 0;
            env.scr_h = screen.height || 0;
            env.scr_ah = screen.availHeight || 0;
            env.color_depth = screen.colorDepth || 0;
            env.plugins_len = navigator.plugins?.length ?? 0;
            env.chrome_obj = !!(window.chrome && (window.chrome.runtime || window.chrome.app));
            env.cpu_cores = navigator.hardwareConcurrency || 0;
            env.fonts = this.detectFonts();
            env.canvas_hash = this.getCanvasHash();
            env.audio_hash = await this.getAudioHash();
            env.webgl_data = this.getWebGLData();
            env.ls = this.checkLocalStorage();
            env.idb = this.checkIndexedDB();
            env.logic_check = this.calculateLogicCheck(env);
        } catch (e) {}
        return env;
    }

    detectCDC() {
        try {
            const keys = Object.keys(document).concat(Object.keys(window));
            return keys.some(k => /^cdc_[a-zA-Z0-9]+/i.test(k) || /^CDC_[a-zA-Z0-9]+/i.test(k));
        } catch { return false; }
    }

    detectProtoTamper() {
        try {
            if (Function.prototype.toString.toString().indexOf('[native code]') === -1) return true;
            const list = [window.alert, window.confirm, window.prompt];
            for (const fn of list) {
                if (fn && typeof fn === 'function' && fn.toString().indexOf('[native code]') === -1) return true;
            }
            return false;
        } catch { return false; }
    }

    detectFonts() {
        try {
            const testFonts = ['Arial', 'Verdana', 'Times New Roman', 'Courier New', 'Georgia', 'Palatino', 'Garamond', 'Comic Sans MS', 'Trebuchet MS', 'Impact'];
            const base = ['monospace', 'sans-serif', 'serif'];
            const s = 'mmmmmmmmmmlli';
            const sz = '72px';
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            const baseW = {};
            base.forEach(f => {
                ctx.font = sz + ' ' + f;
                baseW[f] = ctx.measureText(s).width;
            });
            const res = [];
            testFonts.forEach(f => {
                let ok = false;
                base.forEach(bf => {
                    ctx.font = sz + " ${f}, ${bf}";
                    if (ctx.measureText(s).width !== baseW[bf]) ok = true;
                });
                if (ok) res.push(f);
            });
            return res;
        } catch { return []; }
    }

    getCanvasHash() {
        try {
            const canvas = document.createElement('canvas');
            canvas.width = 200;
            canvas.height = 50;
            const ctx = canvas.getContext('2d');
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillStyle = '#f60';
            ctx.fillRect(0, 0, 200, 50);
            ctx.fillStyle = '#069';
            ctx.fillText('Captcha_Fp_2024', 2, 2);
            ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
            ctx.fillText('Captcha_Fp_2024', 4, 4);
            const url = canvas.toDataURL();
            let hash = 0;
            for (let c of url) hash = ((hash << 5) - hash) + c.charCodeAt(0), hash |= 0;
            return Math.abs(hash);
        } catch { return 0; }
    }

    async getAudioHash() {
        try {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return 0;
            const ctx = new AC();
            const osc = ctx.createOscillator();
            const ana = ctx.createAnalyser();
            const gain = ctx.createGain();
            osc.connect(ana);
            ana.connect(gain);
            gain.connect(ctx.destination);
            osc.type = 'triangle';
            osc.frequency.value = 1000;
            gain.gain.value = 0;
            osc.start();
            await new Promise(r => setTimeout(r, 100));
            const arr = new Float32Array(ana.frequencyBinCount);
            ana.getFloatFrequencyData(arr);
            osc.stop();
            ctx.close();
            let hash = 0;
            for (let v of arr) hash = ((hash << 5) - hash) + Math.round(v * 1000), hash |= 0;
            return Math.abs(hash);
        } catch { return 0; }
    }

    getWebGLData() {
        try {
            const canvas = document.createElement('canvas');
            const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            if (!gl) return {};
            const debug = gl.getExtension('WEBGL_debug_renderer_info');
            return {
                vendor: debug ? gl.getParameter(debug.UNMASKED_VENDOR_WEBGL) : '',
                renderer: debug ? gl.getParameter(debug.UNMASKED_RENDERER_WEBGL) : '',
                max_texture_size: gl.getParameter(gl.MAX_TEXTURE_SIZE),
                max_render_buffer_size: gl.getParameter(gl.MAX_RENDERBUFFER_SIZE)
            };
        } catch { return {}; }
    }

    checkLocalStorage() {
        try {
            const k = '__test_storage';
            localStorage.setItem(k, k);
            localStorage.removeItem(k);
            return true;
        } catch { return false; }
    }

    checkIndexedDB() {
        try { return !!window.indexedDB; } catch { return false; }
    }

    calculateLogicCheck(env) {
        let score = 0;
        if (env.webdriver && env.plugins_len > 0) score++;
        if (env.scr_w <= 0 || env.scr_h <= 0) score++;
        if (env.scr_ah > env.scr_h) score++;
        if (env.cpu_cores <= 0 && env.canvas_hash > 0) score++;
        return score;
    }

    uint8ToBase64(buf) {
        let bin = '';
        const chunk = 8192;
        for (let i = 0; i < buf.length; i += chunk) {
            bin += String.fromCharCode(...buf.subarray(i, i + chunk));
        }
        return btoa(bin);
    }

    base64ToUint8(str) {
        const bin = atob(str);
        const arr = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
        return arr;
    }

    setStatus(type, text) {
        this.statusEl.className = 'auth-status ' + type;
        this.statusEl.innerText = text;
    }

    reset () {
        this.token = '';
        this.segmentKey = '';
        this.bgBase64 = '';
        this.blockBase64 = '';
        this.permEncrypted = '';
        this.currentX = 0;
        this.isVerified = false;
        this.loadingAnimation.style.display = 'flex';
        this.puzzleContainer.style.display = 'none';
        this.puzzleContainer.style.opacity = '1';
        this.sliderTrack.style.background = '#f5f5f5';
        this.sliderTrack.style.borderColor = '#ddd';
        if (this.sliderTrackText) {
            this.sliderTrackText.innerText = ' 拖动滑块完成验证 ';
            this.sliderTrackText.style.color = '#999';
            this.sliderTrackText.style.fontWeight = 'normal';
        }
        this.sliderBtn.style.left = '0px';
        this.puzzleBlock.style.left = '0px';
        this.puzzleBlock.style.filter = 'drop-shadow (2px 2px 4px rgba (0,0,0,0.4))';
        this.sliderBtn.className = 'slider-btn';
        this.startAuthProcess ();
    }
}
