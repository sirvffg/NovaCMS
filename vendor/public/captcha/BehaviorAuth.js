/**
 * 行为认证系统 JS 前端模块
 * 适配博客系统，支持弹窗/内嵌两种模式
 */

class BehaviorAuth {
    constructor(containerId, apiBaseUrl = '/vendor/captcha/AuthApi.php') {
        this.container = document.getElementById(containerId);
        this.apiBaseUrl = apiBaseUrl;

        /** 能否使用 crypto.subtle，以 ensureCryptoSupport() 内实际探测为准（http://127.0.0.1 以外常为 false） */
        this._useSubtle = false;
        this._cryptoReady = false;

        this.token = ''; this.salt = ''; this.difficulty = 0;
        this.bgBase64 = ''; this.blockBase64 = ''; this.blockY = 0;

        this.isDragging = false; this.startX = 0; this.currentX = 0; this.maxX = 0; this.trackScale = 1;
        this.startTime = 0; this.trajectory = []; this.pauseThreshold = 80;
        this.isVerified = false;

        // 图像尺寸参数（与后端一致）
        this.puzzleImageWidth = 300;
        this.blockSize = 44;

        // 回调
        this.onSuccess = null; // 验证成功回调
        this.onFail = null;    // 验证失败回调

        this.init();
    }

    async init() {
        this.renderUI();
        this.bindEvents();
        try {
            await this.ensureCryptoSupport();
            await this.startAuthProcess();
        } catch (e) {
            this.setStatus('fail', e.message || '验证组件初始化失败');
        }
    }

    /**
     * 探测 crypto.subtle（仅安全上下文：HTTPS、http://localhost、http://127.0.0.1）。
     * 用 http://192.168.x.x 或自定义主机名打开时 subtle 不可用，必须加载 CryptoJS。
     */
    async ensureCryptoSupport() {
        if (this._cryptoReady) return;

        let subtleWorks = false;
        try {
            if (typeof crypto !== 'undefined' && crypto.subtle && typeof crypto.subtle.digest === 'function') {
                await crypto.subtle.digest('SHA-256', new Uint8Array([1]));
                subtleWorks = true;
            }
        } catch (e) {
            subtleWorks = false;
        }
        this._useSubtle = subtleWorks;

        if (this._useSubtle) {
            this._cryptoReady = true;
            return;
        }

        if (typeof CryptoJS !== 'undefined') {
            this._cryptoReady = true;
            return;
        }

        const urls = [
            '/vendor/captcha/crypto-js.min.js',
            'https://cdn.jsdelivr.net/npm/crypto-js@4.2.0/crypto-js.min.js',
            'https://unpkg.com/crypto-js@4.2.0/crypto-js.min.js'
        ];
        for (const url of urls) {
            try {
                await new Promise((resolve, reject) => {
                    const s = document.createElement('script');
                    s.src = url;
                    s.async = true;
                    s.onload = resolve;
                    s.onerror = () => reject(new Error('load failed'));
                    document.head.appendChild(s);
                });
                if (typeof CryptoJS !== 'undefined') {
                    this._cryptoReady = true;
                    return;
                }
            } catch (e) {
                /* try next */
            }
        }
        throw new Error(
            '验证码加密库加载失败。本地请使用 http://127.0.0.1 或 http://localhost 打开，或将 crypto-js.min.js 放到 /vendor/captcha/，或改用 HTTPS。'
        );
    }

    static _wordArrayToUint8(wa) {
        const len = wa.sigBytes;
        const words = wa.words;
        const u8 = new Uint8Array(len);
        for (let i = 0; i < len; i++) {
            u8[i] = (words[i >>> 2] >>> (24 - (i % 4) * 8)) & 0xff;
        }
        return u8;
    }

    renderUI() {
        this.container.innerHTML = `
            <style>
                .auth-card { width: 340px; min-width: 340px; background: #fff; border: 1px solid #e0e0e0; padding: 20px; font-family: sans-serif; color: #000; user-select: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); position: relative; box-sizing: content-box; }
                .auth-status { text-align: center; font-size: 12px; margin-bottom: 10px; height: 16px; color: #000; transition: color 0.3s; }
                .auth-status.success { color: #28a745; font-weight: bold; }
                .auth-status.fail { color: #dc3545; }
                .auth-status.loading { color: #6c757d; }

                .puzzle-container { position: relative; width: 300px; min-width: 300px; height: 150px; border-radius: 8px; margin: 0 auto; overflow: hidden; background: #eee; border: 1px solid #ddd; transition: opacity 0.3s; flex-shrink: 0; }
                .puzzle-bg { width: 100%; height: 100%; display: block; border-radius: 8px; object-fit: fill; }

                .puzzle-block { position: absolute; top: 0; left: 0; height: 44px; width: 44px; display: block; filter: drop-shadow(2px 2px 4px rgba(0,0,0,0.4)); transition: filter 0.2s; z-index: 10; }

                .slider-track { width: 300px; min-width: 300px; height: 40px; background: #f5f5f5; border-radius: 6px; margin: 15px auto 0; position: relative; border: 1px solid #ddd; overflow: hidden; transition: opacity 0.3s, background 0.3s, border-color 0.3s; }
                .slider-track-text { position: absolute; width: 100%; text-align: center; line-height: 40px; font-size: 12px; color: #999; pointer-events: none; z-index: 1; transition: color 0.3s; }
                .slider-btn { width: 40px; height: 40px; background: #fff; border-radius: 6px; position: absolute; top: 0px; left: 0px; cursor: pointer; z-index: 2; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: flex; align-items: center; justify-content: center; transition: box-shadow 0.2s, background 0.3s; }
                .slider-btn::after { content: '►'; color: #666; font-size: 16px; transition: color 0.3s; }
                .slider-btn:active { box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
                .slider-btn.success { background: #28a745; } .slider-btn.success::after { color: #fff; content: '✓'; }
                .slider-btn.fail { background: #dc3545; } .slider-btn.fail::after { color: #fff; content: '✕'; }

                .auth-brand { position: absolute; bottom: 8px; right: 12px; font-size: 10px; color: #ccc; pointer-events: none; letter-spacing: 0.5px; }
            </style>
            <div class="auth-card">
                <div class="auth-status loading" id="auth-status">加载中...</div>

                <div class="puzzle-container" id="puzzle-container" style="display:none;">
                    <img class="puzzle-bg" id="puzzle-bg" src="" alt="">
                    <img class="puzzle-block" id="puzzle-block" src="" alt="">
                </div>

                <div class="slider-track" id="slider-track" style="display:none;">
                    <div class="slider-track-text" id="slider-track-text">拖动滑块完成验证</div>
                    <div class="slider-btn" id="slider-btn"></div>
                </div>

                <div class="auth-brand">Dino Captcha</div>
            </div>
        `;

        this.statusEl = document.getElementById('auth-status');
        this.puzzleContainer = document.getElementById('puzzle-container');
        this.puzzleBg = document.getElementById('puzzle-bg');
        this.puzzleBlock = document.getElementById('puzzle-block');
        this.sliderTrack = document.getElementById('slider-track');
        this.sliderTrackText = document.getElementById('slider-track-text');
        this.sliderBtn = document.getElementById('slider-btn');
    }

    bindEvents() {
        this.sliderBtn.addEventListener('mousedown', (e) => this.onDragStart(e.clientX, e.clientY));
        document.addEventListener('mousemove', (e) => this.onDragMove(e.clientX, e.clientY));
        document.addEventListener('mouseup', () => this.onDragEnd());

        this.sliderBtn.addEventListener('touchstart', (e) => { e.preventDefault(); this.onDragStart(e.touches[0].clientX, e.touches[0].clientY); });
        document.addEventListener('touchmove', (e) => this.onDragMove(e.touches[0].clientX, e.touches[0].clientY));
        document.addEventListener('touchend', () => this.onDragEnd());
    }

    async startAuthProcess() {
        try {
            this.setStatus('loading', '获取验证参数...');
            const initData = await this.api('init');
            this.token = initData.token; this.salt = initData.salt; this.difficulty = initData.difficulty;

            this.setStatus('loading', '验证中...');
            const nonce = await this.solvePOW(this.salt, this.difficulty);

            this.setStatus('loading', '验证初始化...');
            await this.api('verify-pow', { token: this.token, nonce: nonce });

            this.setStatus('loading', '加载中...');
            const puzzleData = await this.api('get-puzzle', { token: this.token });
            this.bgBase64 = puzzleData.bg_base64;
            this.blockBase64 = puzzleData.block_base64;
            this.blockY = puzzleData.block_y;

            this.renderPuzzle();
        } catch (error) {
            this.setStatus('fail', error.message || '验证初始化失败');
        }
    }

    renderPuzzle() {
        this.puzzleBg.src = 'data:image/png;base64,' + this.bgBase64;
        this.puzzleBlock.src = 'data:image/png;base64,' + this.blockBase64;
        this.puzzleBlock.style.top = this.blockY + 'px';
        this.puzzleBlock.style.left = '0px';
        this.puzzleBlock.style.display = 'block';

        this.puzzleContainer.style.display = 'block';
        this.sliderTrack.style.display = 'block';
        this.setStatus('', '请完成验证');
        this.maxX = 300 - 40; // trackWidth - btnWidth，固定常量避免缩放问题
    }

    onDragStart(clientX, clientY) {
        if (!this.blockBase64 || this.isDragging || this.isVerified) return;
        this.isDragging = true;
        this.startX = clientX;
        // 记录拖动开始时滑块轨道的缩放比，用于校正窗口缩放导致的鼠标偏移
        const trackRect = this.sliderTrack.getBoundingClientRect();
        this.trackScale = trackRect.width / 300; // 300 是轨道设计宽度
        this.startTime = Date.now();
        this.trajectory = [];
        this.recordMousePosition(clientX, clientY);
    }

    onDragMove(clientX, clientY) {
        if (!this.isDragging) return;

        // 校正缩放：鼠标偏移 / 缩放比 = 实际在轨道上的偏移
        const deltaX = (clientX - this.startX) / this.trackScale;
        this.currentX = Math.max(0, Math.min(deltaX, this.maxX));

        this.sliderBtn.style.left = this.currentX + 'px';

        // 滑块偏移 → 图像坐标的比例换算（使用固定常量，避免窗口缩放导致 offsetWidth 不准）
        const sliderMaxX = 300 - 40; // trackWidth - btnWidth
        const imageMaxX = this.puzzleImageWidth - this.blockSize; // 300 - 44 = 256
        const imageX = this.currentX / sliderMaxX * imageMaxX;
        this.puzzleBlock.style.left = imageX + 'px';

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

    async onDragEnd() {
        if (!this.isDragging) return;
        this.isDragging = false;
        if (this.currentX === 0) return;

        this.setStatus('loading', '校验中...');
        const behaviorData = this.analyzeBehavior();
        const encryptedData = await this.encryptBehaviorData(behaviorData, this.token);

        // 用固定常量换算到图像坐标，避免窗口缩放导致 offsetWidth 不准
        // 滑块偏移 / 滑块最大偏移 = 图像偏移 / 图像最大偏移
        const sliderMaxX = 300 - 40; // trackWidth - btnWidth
        const imageMaxX = this.puzzleImageWidth - this.blockSize; // 300 - 44 = 256
        const imageOffsetX = Math.round(this.currentX / sliderMaxX * imageMaxX);

        try {
            const result = await this.api('verify-final', {
                token: this.token, offset_x: imageOffsetX, behavior_data: encryptedData
            });

            if (result.code === 200) {
                this.isVerified = true;
                this.setStatus('success', '验证成功');
                this.sliderBtn.classList.add('success');

                this.puzzleContainer.style.opacity = '0';
                setTimeout(() => { this.puzzleContainer.style.display = 'none'; }, 300);

                this.sliderBtn.style.left = this.maxX + 'px';
                this.sliderTrack.style.background = '#e8f5e9';
                this.sliderTrack.style.borderColor = '#28a745';

                if (this.sliderTrackText) {
                    this.sliderTrackText.innerText = '验证成功';
                    this.sliderTrackText.style.color = '#28a745';
                    this.sliderTrackText.style.fontWeight = 'bold';
                }

                if (this.onSuccess) this.onSuccess(result.token);
            } else {
                throw new Error(result.msg);
            }
        } catch (error) {
            this.setStatus('fail', error.message || '验证失败');
            this.sliderBtn.classList.add('fail');
            this.puzzleBlock.style.filter = 'drop-shadow(2px 2px 4px rgba(220,53,69,0.8))';
            if (this.onFail) this.onFail(error.message);
            setTimeout(() => this.reset(), 2000);
        }
    }

    analyzeBehavior() {
        const duration = Date.now() - this.startTime;
        let pauseCount = 0, totalPauseTime = 0, speeds = [];

        for (let i = 1; i < this.trajectory.length; i++) {
            const prev = this.trajectory[i - 1]; const curr = this.trajectory[i];
            const dt = curr.t - prev.t;
            const dx = Math.abs(curr.x - prev.x); const dy = Math.abs(curr.y - prev.y);
            const dist = Math.sqrt(dx * dx + dy * dy);

            if (dt > 0) speeds.push(dist / dt);
            if (dt > this.pauseThreshold && dist < 2) { pauseCount++; totalPauseTime += dt; }
        }

        const avgSpeed = speeds.reduce((a, b) => a + b, 0) / speeds.length;
        const speedVariance = speeds.reduce((acc, speed) => acc + Math.pow(speed - avgSpeed, 2), 0) / speeds.length;

        return { duration, pause_count: pauseCount, total_pause_time: totalPauseTime, speed_variance: isNaN(speedVariance) ? 0 : speedVariance };
    }

    async encryptBehaviorData(data, token) {
        await this.ensureCryptoSupport();
        const plain = new TextEncoder().encode(JSON.stringify(data));

        if (this._useSubtle) {
            const tokenHashBuffer = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(token));
            const tokenHashHex = Array.from(new Uint8Array(tokenHashBuffer)).map(b => b.toString(16).padStart(2, '0')).join('');
            const keyBytes = new TextEncoder().encode(tokenHashHex.substring(0, 32));
            const key = await crypto.subtle.importKey('raw', keyBytes, { name: 'AES-CBC' }, false, ['encrypt']);
            const iv = crypto.getRandomValues(new Uint8Array(16));
            const encryptedBuffer = await crypto.subtle.encrypt({ name: 'AES-CBC', iv: iv }, key, plain);
            const combinedArray = new Uint8Array(iv.length + encryptedBuffer.byteLength);
            combinedArray.set(iv);
            combinedArray.set(new Uint8Array(encryptedBuffer), iv.length);
            let binary = '';
            for (let i = 0; i < combinedArray.length; i++) {
                binary += String.fromCharCode(combinedArray[i]);
            }
            return btoa(binary);
        }

        const tokenHashHex = CryptoJS.SHA256(token).toString(CryptoJS.enc.Hex);
        const key = CryptoJS.enc.Utf8.parse(tokenHashHex.substring(0, 32));
        const ivWa = CryptoJS.lib.WordArray.random(16);
        const enc = CryptoJS.AES.encrypt(JSON.stringify(data), key, {
            iv: ivWa,
            mode: CryptoJS.mode.CBC,
            padding: CryptoJS.pad.Pkcs7
        });
        const ivU8 = BehaviorAuth._wordArrayToUint8(ivWa);
        const ctU8 = BehaviorAuth._wordArrayToUint8(enc.ciphertext);
        const combinedArray = new Uint8Array(ivU8.length + ctU8.length);
        combinedArray.set(ivU8);
        combinedArray.set(ctU8, ivU8.length);
        let binary = '';
        for (let i = 0; i < combinedArray.length; i++) {
            binary += String.fromCharCode(combinedArray[i]);
        }
        return btoa(binary);
    }

    async solvePOW(salt, difficulty) {
        await this.ensureCryptoSupport();
        const prefix = '0'.repeat(difficulty);
        let nonce = 0;

        // WASM 加速（不依赖 crypto.subtle）
        if (typeof hashwasm !== 'undefined') {
            try {
                while (true) {
                    for (let i = 0; i < 5000; i++) {
                        if ((await hashwasm.sha256(salt + nonce)).startsWith(prefix)) return nonce;
                        nonce++;
                    }
                    await new Promise(r => setTimeout(r, 0));
                }
            } catch (e) {
                console.warn('[Captcha] hashwasm 不可用，改用其它方式', e);
            }
        }

        // 无可用 Web Crypto 时只用 CryptoJS（禁止落到下面的 digest，否则会 digest undefined）
        if (!this._useSubtle) {
            if (typeof CryptoJS === 'undefined') {
                throw new Error('加密库未就绪，请刷新页面');
            }
            while (true) {
                for (let i = 0; i < 5000; i++) {
                    const hex = CryptoJS.SHA256(salt + (nonce + i)).toString(CryptoJS.enc.Hex);
                    if (hex.startsWith(prefix)) return nonce + i;
                }
                nonce += 5000;
                await new Promise(r => setTimeout(r, 0));
            }
        }

        while (true) {
            const batch = [];
            for (let i = 0; i < 500; i++) {
                batch.push(new TextEncoder().encode(salt + (nonce + i)));
            }
            for (let i = 0; i < batch.length; i++) {
                const buf = await crypto.subtle.digest('SHA-256', batch[i]);
                const hex = Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
                if (hex.startsWith(prefix)) return nonce + i;
            }
            nonce += batch.length;
            await new Promise(r => setTimeout(r, 0));
        }
    }

    async api(action, data = null) {
        const isGet = (action === 'init' || action === 'get-puzzle');
        let url = this.apiBaseUrl + '?action=' + action;
        let options = { method: 'POST', headers: { 'Content-Type': 'application/json' } };
        if (isGet && data && data.token) { url += '&token=' + data.token; options.method = 'GET'; }
        else if (data) { options.body = JSON.stringify(data); }
        const response = await fetch(url, options);
        const text = await response.text();
        let result;
        try { result = JSON.parse(text); } catch(e) { throw new Error('Invalid JSON: ' + text.substring(0, 200)); }
        if (response.status !== 200 || result.code !== 200) {
            console.error('[Captcha API Error]', action, result);
            throw new Error(result.msg || 'Request failed');
        }
        return result;
    }

    setStatus(type, text) { this.statusEl.className = 'auth-status ' + type; this.statusEl.innerText = text; }

    reset() {
        this.token = ''; this.bgBase64 = ''; this.blockBase64 = ''; this.currentX = 0;
        this.isVerified = false;

        this.puzzleContainer.style.display = 'none'; this.sliderTrack.style.display = 'none';
        this.puzzleContainer.style.opacity = '1';

        this.sliderTrack.style.background = '#f5f5f5';
        this.sliderTrack.style.borderColor = '#ddd';
        if (this.sliderTrackText) {
            this.sliderTrackText.innerText = '拖动滑块完成验证';
            this.sliderTrackText.style.color = '#999';
            this.sliderTrackText.style.fontWeight = 'normal';
        }

        this.sliderBtn.style.left = '0px';
        this.puzzleBlock.style.left = '0px';
        this.puzzleBlock.style.filter = 'drop-shadow(2px 2px 4px rgba(0,0,0,0.4))';
        this.sliderBtn.className = 'slider-btn';

        this.startAuthProcess();
    }
}
