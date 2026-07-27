// 全局变量
let imageFiles = [];
let compressionHistory = [];
let currentPreset = 'balanced';

// 预设配置
const presets = {
    balanced: { quality: 80, maxWidth: null, maxHeight: null, format: 'original' },
    quality: { quality: 95, maxWidth: null, maxHeight: null, format: 'original' },
    size: { quality: 60, maxWidth: 1920, maxHeight: 1080, format: 'jpeg' },
    web: { quality: 85, maxWidth: 1200, maxHeight: 800, format: 'webp' },
    social: { quality: 90, maxWidth: 1080, maxHeight: 1080, format: 'jpeg' }
};

// DOM 元素
const uploadArea = document.getElementById('uploadArea');
const fileInput = document.getElementById('fileInput');
const compressionControls = document.getElementById('compressionControls');
const progressSection = document.getElementById('progressSection');
const imagesSection = document.getElementById('imagesSection');
const historySection = document.getElementById('historySection');
const qualitySlider = document.getElementById('qualitySlider');
const qualityValue = document.getElementById('qualityValue');
const maxWidthInput = document.getElementById('maxWidth');
const maxHeightInput = document.getElementById('maxHeight');
const outputFormat = document.getElementById('outputFormat');
const compressBtn = document.getElementById('compressBtn');
const clearBtn = document.getElementById('clearBtn');
const downloadAllBtn = document.getElementById('downloadAllBtn');
const progressFill = document.getElementById('progressFill');
const progressText = document.getElementById('progressText');
const imagesGrid = document.getElementById('imagesGrid');
const historyList = document.getElementById('historyList');
const clearHistoryBtn = document.getElementById('clearHistoryBtn');
const showHistoryBtn = document.getElementById('showHistoryBtn');
const showHelpBtn = document.getElementById('showHelpBtn');
const helpModal = document.getElementById('helpModal');
const closeHelpModal = document.getElementById('closeHelpModal');
const themeToggle = document.getElementById('themeToggle');

// 初始化
document.addEventListener('DOMContentLoaded', function() {
    initializeEventListeners();
    loadCompressionHistory();
    updateQualityDisplay();
    applyPreset(currentPreset);
});

// 初始化事件监听器
function initializeEventListeners() {
    // 文件输入变化
    fileInput.addEventListener('change', handleFileSelect);
    
    // 拖拽上传
    uploadArea.addEventListener('dragover', handleDragOver);
    uploadArea.addEventListener('dragleave', handleDragLeave);
    uploadArea.addEventListener('drop', handleDrop);
    
    // 质量滑块
    qualitySlider.addEventListener('input', updateQualityDisplay);
    
    // 预设按钮
    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.addEventListener('click', handlePresetClick);
    });
    
    // 压缩按钮
    compressBtn.addEventListener('click', compressAllImages);
    
    // 清空按钮
    clearBtn.addEventListener('click', clearAllImages);
    
    // 下载全部按钮
    downloadAllBtn.addEventListener('click', downloadAllImages);
    
    // 历史相关
    showHistoryBtn.addEventListener('click', toggleHistory);
    clearHistoryBtn.addEventListener('click', clearHistory);
    
    // 帮助模态框
    showHelpBtn.addEventListener('click', showHelp);
    closeHelpModal.addEventListener('click', hideHelp);
    
    // 主题切换
    themeToggle.addEventListener('click', toggleTheme);
    
    // 快捷键
    document.addEventListener('keydown', handleKeyboard);
    
    // 尺寸输入框变化时自动压缩
    maxWidthInput.addEventListener('input', debounce(autoCompress, 500));
    maxHeightInput.addEventListener('input', debounce(autoCompress, 500));
    outputFormat.addEventListener('change', debounce(autoCompress, 500));
}

// 处理文件选择
function handleFileSelect(event) {
    const files = Array.from(event.target.files);
    processFiles(files);
}

// 处理拖拽悬停
function handleDragOver(event) {
    event.preventDefault();
    uploadArea.classList.add('dragover');
}

// 处理拖拽离开
function handleDragLeave(event) {
    event.preventDefault();
    uploadArea.classList.remove('dragover');
}

// 处理文件拖拽
function handleDrop(event) {
    event.preventDefault();
    uploadArea.classList.remove('dragover');
    
    const files = Array.from(event.dataTransfer.files);
    processFiles(files);
}

// 处理文件
function processFiles(files) {
    const validFiles = files.filter(isValidImageFile);
    
    if (validFiles.length === 0) {
        showError('请选择有效的图片文件 (PNG, JPG, JPEG, WebP)');
        return;
    }
    
    // 添加到图片列表
    imageFiles = [...imageFiles, ...validFiles];
    
    // 显示压缩控制
    compressionControls.style.display = 'block';
    
    // 显示图片列表
    displayImagesList();
    
    // 自动压缩
    setTimeout(() => {
        compressAllImages();
    }, 100);
}

// 验证图片文件
function isValidImageFile(file) {
    const validTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
    const maxSize = 10 * 1024 * 1024; // 10MB
    
    if (!validTypes.includes(file.type)) {
        return false;
    }
    
    if (file.size > maxSize) {
        return false;
    }
    
    return true;
}

// 显示图片列表
function displayImagesList() {
    if (imageFiles.length === 0) {
        imagesSection.style.display = 'none';
        return;
    }
    
    imagesSection.style.display = 'block';
    imagesGrid.innerHTML = '';
    
    imageFiles.forEach((file, index) => {
        const imageItem = createImageItem(file, index);
        imagesGrid.appendChild(imageItem);
    });
}

// 创建图片项
function createImageItem(file, index) {
    const item = document.createElement('div');
    item.className = 'image-item';
    item.dataset.index = index;
    
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
            item.innerHTML = `
                <div class="image-item-header">
                    <div class="image-name">${file.name}</div>
                    <div class="image-actions">
                        <button class="image-action-btn" onclick="removeImage(${index})" title="删除">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M19 7L18.1327 19.1425C18.0579 20.1891 17.187 21 16.1378 21H7.86224C6.81296 21 5.94208 20.1891 5.86732 19.1425L5 7M10 11V17M14 11V17M15 7V4C15 3.44772 14.5523 3 14 3H10C9.44772 3 9 3.44772 9 4V7M4 7H20" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="image-preview">
                    <div class="preview-container">
                        <img src="${e.target.result}" alt="原始图片">
                        <div class="preview-label">原始</div>
                    </div>
                    <div class="preview-container" id="compressed-${index}">
                        <div class="preview-label">压缩后</div>
                    </div>
                </div>
                <div class="image-info">
                    <div class="info-item">
                        <span class="info-label">原始大小:</span>
                        <span class="info-value">${formatFileSize(file.size)}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">尺寸:</span>
                        <span class="info-value">${img.width} × ${img.height}</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">压缩后大小:</span>
                        <span class="info-value" id="compressed-size-${index}">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">压缩率:</span>
                        <span class="info-value" id="compression-ratio-${index}">-</span>
                    </div>
                </div>
                <button class="image-download" onclick="downloadImage(${index})" id="download-${index}" style="display: none;">
                    <svg class="btn-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M19 9H15V3H9V9H5L12 16L19 9ZM5 18V20H19V18H5Z" fill="currentColor"/>
                    </svg>
                    下载
                </button>
            `;
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
    
    return item;
}

// 删除图片
function removeImage(index) {
    imageFiles.splice(index, 1);
    displayImagesList();
    
    if (imageFiles.length === 0) {
        compressionControls.style.display = 'none';
    }
}

// 清空所有图片
function clearAllImages() {
    imageFiles = [];
    displayImagesList();
    compressionControls.style.display = 'none';
}

// 处理预设点击
function handlePresetClick(event) {
    const preset = event.target.dataset.preset;
    
    // 更新按钮状态
    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
    
    // 应用预设
    applyPreset(preset);
    currentPreset = preset;
}

// 应用预设
function applyPreset(preset) {
    const config = presets[preset];
    
    qualitySlider.value = config.quality;
    qualityValue.textContent = `${config.quality}%`;
    
    if (config.maxWidth) {
        maxWidthInput.value = config.maxWidth;
    } else {
        maxWidthInput.value = '';
    }
    
    if (config.maxHeight) {
        maxHeightInput.value = config.maxHeight;
    } else {
        maxHeightInput.value = '';
    }
    
    outputFormat.value = config.format;
}

// 更新质量显示
function updateQualityDisplay() {
    const quality = qualitySlider.value;
    qualityValue.textContent = `${quality}%`;
}

// 压缩所有图片
async function compressAllImages() {
    if (imageFiles.length === 0) {
        showError('请先选择图片');
        return;
    }
    
    // 显示进度条
    progressSection.style.display = 'block';
    compressBtn.disabled = true;
    
    const totalImages = imageFiles.length;
    let completedImages = 0;
    
    for (let i = 0; i < imageFiles.length; i++) {
        const file = imageFiles[i];
        await compressSingleImage(file, i);
        
        completedImages++;
        const progress = (completedImages / totalImages) * 100;
        progressFill.style.width = `${progress}%`;
        progressText.textContent = `压缩中... ${Math.round(progress)}%`;
    }
    
    // 隐藏进度条
    progressSection.style.display = 'none';
    compressBtn.disabled = false;
    
    // 保存到历史记录
    saveToHistory();
    
    showSuccess(`成功压缩 ${totalImages} 张图片`);
}

// 压缩单张图片
async function compressSingleImage(file, index) {
    return new Promise((resolve) => {
        const quality = parseInt(qualitySlider.value) / 100;
        const maxWidth = parseInt(maxWidthInput.value) || null;
        const maxHeight = parseInt(maxHeightInput.value) || null;
        const format = outputFormat.value;
        
        const reader = new FileReader();
        reader.onload = function(e) {
            const img = new Image();
            img.onload = function() {
                // 计算新尺寸
                const { width, height } = calculateNewDimensions(img.width, img.height, maxWidth, maxHeight);
                
                // 创建 Canvas
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                
                canvas.width = width;
                canvas.height = height;
                
                // 绘制图片
                ctx.drawImage(img, 0, 0, width, height);
                
                // 确定输出格式
                let outputType = file.type;
                if (format !== 'original') {
                    switch (format) {
                        case 'jpeg':
                            outputType = 'image/jpeg';
                            break;
                        case 'png':
                            outputType = 'image/png';
                            break;
                        case 'webp':
                            outputType = 'image/webp';
                            break;
                    }
                }
                
                // 转换为 Blob
                canvas.toBlob((blob) => {
                    if (blob) {
                        // 更新压缩后信息
                        const compressedSize = blob.size;
                        const originalSize = file.size;
                        const ratio = ((originalSize - compressedSize) / originalSize * 100).toFixed(1);
                        
                        // 更新显示
                        const compressedContainer = document.getElementById(`compressed-${index}`);
                        if (compressedContainer) {
                            const url = URL.createObjectURL(blob);
                            compressedContainer.innerHTML = `
                                <img src="${url}" alt="压缩后图片">
                                <div class="preview-label">压缩后</div>
                            `;
                        }
                        
                        // 更新信息
                        const sizeElement = document.getElementById(`compressed-size-${index}`);
                        const ratioElement = document.getElementById(`compression-ratio-${index}`);
                        const downloadBtn = document.getElementById(`download-${index}`);
                        
                        if (sizeElement) sizeElement.textContent = formatFileSize(compressedSize);
                        if (ratioElement) ratioElement.textContent = `${ratio}%`;
                        if (downloadBtn) {
                            downloadBtn.style.display = 'flex';
                            downloadBtn.onclick = () => downloadImage(index, blob);
                        }
                        
                        // 保存压缩结果
                        file.compressedBlob = blob;
                        file.compressedSize = compressedSize;
                        file.compressionRatio = ratio;
                    }
                    resolve();
                }, outputType, quality);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
}

// 计算新尺寸
function calculateNewDimensions(originalWidth, originalHeight, maxWidth, maxHeight) {
    let width = originalWidth;
    let height = originalHeight;
    
    if (!maxWidth && !maxHeight) {
        return { width, height };
    }
    
    if (maxWidth && maxHeight) {
        const widthRatio = maxWidth / width;
        const heightRatio = maxHeight / height;
        const ratio = Math.min(widthRatio, heightRatio);
        
        if (ratio < 1) {
            width = Math.round(width * ratio);
            height = Math.round(height * ratio);
        }
    } else if (maxWidth && width > maxWidth) {
        const ratio = maxWidth / width;
        width = maxWidth;
        height = Math.round(height * ratio);
    } else if (maxHeight && height > maxHeight) {
        const ratio = maxHeight / height;
        height = maxHeight;
        width = Math.round(width * ratio);
    }
    
    return { width, height };
}

// 自动压缩
function autoCompress() {
    if (imageFiles.length > 0) {
        compressAllImages();
    }
}

// 下载单张图片
function downloadImage(index, blob = null) {
    const file = imageFiles[index];
    const downloadBlob = blob || file.compressedBlob;
    
    if (!downloadBlob) {
        showError('没有可下载的压缩图片');
        return;
    }
    
    const url = URL.createObjectURL(downloadBlob);
    const link = document.createElement('a');
    link.href = url;
    
    // 生成文件名
    const originalName = file.name;
    const extension = originalName.split('.').pop();
    const baseName = originalName.replace(`.${extension}`, '');
    const quality = qualitySlider.value;
    const format = outputFormat.value;
    
    let newExtension = extension;
    if (format !== 'original') {
        newExtension = format;
    }
    
    link.download = `${baseName}_compressed_${quality}%.${newExtension}`;
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    URL.revokeObjectURL(url);
}

// 下载所有图片
function downloadAllImages() {
    if (imageFiles.length === 0) {
        showError('没有可下载的图片');
        return;
    }
    
    imageFiles.forEach((file, index) => {
        if (file.compressedBlob) {
            setTimeout(() => {
                downloadImage(index);
            }, index * 100);
        }
    });
}

// 保存到历史记录
function saveToHistory() {
    const historyItem = {
        id: Date.now(),
        timestamp: new Date().toISOString(),
        images: imageFiles.map(file => ({
            name: file.name,
            originalSize: file.size,
            compressedSize: file.compressedSize,
            compressionRatio: file.compressionRatio,
            thumbnail: URL.createObjectURL(file)
        })),
        settings: {
            quality: qualitySlider.value,
            maxWidth: maxWidthInput.value,
            maxHeight: maxHeightInput.value,
            format: outputFormat.value,
            preset: currentPreset
        }
    };
    
    compressionHistory.unshift(historyItem);
    
    // 限制历史记录数量
    if (compressionHistory.length > 50) {
        compressionHistory = compressionHistory.slice(0, 50);
    }
    
    localStorage.setItem('compressionHistory', JSON.stringify(compressionHistory));
}

// 加载压缩历史
function loadCompressionHistory() {
    const saved = localStorage.getItem('compressionHistory');
    if (saved) {
        compressionHistory = JSON.parse(saved);
    }
}

// 显示历史记录
function toggleHistory() {
    if (historySection.style.display === 'none') {
        displayHistory();
        historySection.style.display = 'block';
        imagesSection.style.display = 'none';
    } else {
        historySection.style.display = 'none';
        imagesSection.style.display = 'block';
    }
}

// 显示历史记录
function displayHistory() {
    if (compressionHistory.length === 0) {
        historyList.innerHTML = '<p style="text-align: center; color: var(--text-secondary);">暂无压缩历史</p>';
        return;
    }
    
    historyList.innerHTML = '';
    
    compressionHistory.forEach(item => {
        const historyItem = document.createElement('div');
        historyItem.className = 'history-item';
        historyItem.onclick = () => loadHistoryItem(item);
        
        const totalImages = item.images.length;
        const avgRatio = item.images.reduce((sum, img) => sum + parseFloat(img.compressionRatio), 0) / totalImages;
        
        historyItem.innerHTML = `
            <img src="${item.images[0].thumbnail}" alt="缩略图" class="history-thumbnail">
            <div class="history-info">
                <div class="history-name">${totalImages} 张图片</div>
                <div class="history-details">
                    平均压缩率: ${avgRatio.toFixed(1)}% | 
                    ${new Date(item.timestamp).toLocaleString()}
                </div>
            </div>
            <div class="history-actions">
                <button class="image-action-btn" onclick="event.stopPropagation(); deleteHistoryItem(${item.id})" title="删除">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M19 7L18.1327 19.1425C18.0579 20.1891 17.187 21 16.1378 21H7.86224C6.81296 21 5.94208 20.1891 5.86732 19.1425L5 7M10 11V17M14 11V17M15 7V4C15 3.44772 14.5523 3 14 3H10C9.44772 3 9 3.44772 9 4V7M4 7H20" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
        `;
        
        historyList.appendChild(historyItem);
    });
}

// 加载历史记录项
function loadHistoryItem(item) {
    // 应用设置
    qualitySlider.value = item.settings.quality;
    qualityValue.textContent = `${item.settings.quality}%`;
    maxWidthInput.value = item.settings.maxWidth || '';
    maxHeightInput.value = item.settings.maxHeight || '';
    outputFormat.value = item.settings.format;
    
    // 更新预设按钮
    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.classList.remove('active');
        if (btn.dataset.preset === item.settings.preset) {
            btn.classList.add('active');
        }
    });
    
    currentPreset = item.settings.preset;
    
    // 切换回图片列表
    historySection.style.display = 'none';
    imagesSection.style.display = 'block';
    
    showSuccess('已加载历史设置');
}

// 删除历史记录项
function deleteHistoryItem(id) {
    const index = compressionHistory.findIndex(item => item.id === id);
    if (index > -1) {
        compressionHistory.splice(index, 1);
        localStorage.setItem('compressionHistory', JSON.stringify(compressionHistory));
        displayHistory();
    }
}

// 清空历史记录
function clearHistory() {
    if (confirm('确定要清空所有压缩历史吗？')) {
        compressionHistory = [];
        localStorage.removeItem('compressionHistory');
        displayHistory();
    }
}

// 显示帮助
function showHelp() {
    helpModal.style.display = 'flex';
}

// 隐藏帮助
function hideHelp() {
    helpModal.style.display = 'none';
}

// 切换主题
function toggleTheme() {
    document.body.classList.toggle('dark-theme');
    const isDark = document.body.classList.contains('dark-theme');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
}

// 处理键盘事件
function handleKeyboard(event) {
    if (event.ctrlKey || event.metaKey) {
        switch (event.key) {
            case 'z':
                event.preventDefault();
                // 撤销操作
                break;
            case 's':
                event.preventDefault();
                downloadAllImages();
                break;
        }
    }
    
    if (event.key === 'Delete') {
        // 删除选中的图片
        const selectedItem = document.querySelector('.image-item.selected');
        if (selectedItem) {
            const index = parseInt(selectedItem.dataset.index);
            removeImage(index);
        }
    }
}

// 格式化文件大小
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// 显示错误信息
function showError(message) {
    showNotification(message, 'error');
}

// 显示成功信息
function showSuccess(message) {
    showNotification(message, 'success');
}

// 显示通知
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    
    const colors = {
        error: '#FF3B30',
        success: '#34C759',
        info: '#007AFF'
    };
    
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${colors[type]};
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 12px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3);
        z-index: 1000;
        font-weight: 500;
        animation: slideInRight 0.3s ease-out;
        max-width: 300px;
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease-out';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 3000);
}

// 防抖函数
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// 添加动画样式
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
    
    .dark-theme {
        --background-color: #1C1C1E;
        --surface-color: #2C2C2E;
        --text-primary: #FFFFFF;
        --text-secondary: #98989D;
        --text-tertiary: #48484A;
        --border-color: #38383A;
    }
    
    .image-item.selected {
        border: 2px solid var(--primary-color);
        box-shadow: 0 0 0 4px rgba(0, 122, 255, 0.1);
    }
`;
document.head.appendChild(style);

// 加载保存的主题
const savedTheme = localStorage.getItem('theme');
if (savedTheme === 'dark') {
    document.body.classList.add('dark-theme');
} 