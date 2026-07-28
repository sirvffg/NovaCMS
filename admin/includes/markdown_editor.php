<!-- Markdown 编辑器样式和脚本 -->
<link rel="stylesheet" href="<?= getResourceUrl('/assets/css/easymde.min.css', 'https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css') ?>">
<script src="<?= getResourceUrl('/assets/js/easymde.min.js', 'https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js') ?>"></script>

<style>
.editor-toolbar {
    border-radius: 5px 5px 0 0;
}
.CodeMirror {
    border-radius: 0 0 5px 5px;
    min-height: 300px;
}
.editor-preview {
    padding: 10px;
}
.editor-preview img {
    max-width: 100%;
    height: auto;
}

/* 上传进度条样式 */
.upload-progress-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

.upload-progress-container {
    background: white;
    border-radius: 8px;
    padding: 24px;
    min-width: 400px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.upload-progress-header {
    display: flex;
    align-items: center;
    margin-bottom: 16px;
}

.upload-progress-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e3f2fd;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 20px;
}

.upload-progress-info h5 {
    margin: 0;
    font-size: 16px;
    color: #333;
}

.upload-progress-info p {
    margin: 4px 0 0 0;
    font-size: 13px;
    color: #666;
}

.upload-progress-bar-container {
    background: #f0f0f0;
    border-radius: 4px;
    height: 8px;
    overflow: hidden;
    margin-bottom: 8px;
}

.upload-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #2196F3, #1976D2);
    transition: width 0.3s ease;
    border-radius: 4px;
}

.upload-progress-text {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: #666;
}

.upload-progress-success {
    color: #4CAF50;
    font-weight: 500;
}

.upload-progress-error {
    color: #f44336;
    font-weight: 500;
}

/* 新增功能按钮样式 */
.editor-toolbar button {
    transition: all 0.2s ease;
    flex-shrink: 0;
}

/* 表情选择器样式优化 */
.emoji-picker-grid {
    display: grid;
    grid-template-columns: repeat(8, 1fr);
    gap: 5px;
    max-height: 300px;
    overflow-y: auto;
}

.emoji-button {
    padding: 8px;
    font-size: 18px;
    border: 1px solid #ddd;
    background: white;
    cursor: pointer;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.emoji-button:hover {
    background: #f5f5f5;
    transform: scale(1.2);
}

/* 数学公式对话框样式优化 */
.math-formula-template {
    padding: 8px;
    text-align: left;
    border: 1px solid #ddd;
    background: white;
    cursor: pointer;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.math-formula-template:hover {
    background: #e3f2fd;
    border-color: #2196F3;
}

/* 表格预览样式 */
.editor-preview table {
    border-collapse: collapse;
    width: 100%;
    margin: 10px 0;
}

.editor-preview th,
.editor-preview td {
    border: 1px solid #ddd;
    padding: 8px 12px;
    text-align: left;
}

.editor-preview th {
    background-color: #f5f5f5;
    font-weight: bold;
}

.editor-preview tr:nth-child(even) {
    background-color: #f9f9f9;
}

/* 数学公式预览样式 */
.editor-preview .math-inline {
    font-family: 'Times New Roman', serif;
    font-style: italic;
}

.editor-preview .math-block {
    font-family: 'Times New Roman', serif;
    text-align: center;
    margin: 15px 0;
    padding: 10px;
    background: #f8f8f8;
    border-radius: 4px;
    overflow-x: auto;
}

/* 任务列表样式 */
.editor-preview .task-list-item {
    list-style: none;
    margin-left: -20px;
}

.editor-preview .task-list-item input[type="checkbox"] {
    margin-right: 8px;
}

/* 代码块高亮增强 */
.editor-preview pre {
    background: #f6f8fa;
    border-radius: 6px;
    padding: 16px;
    overflow-x: auto;
    margin: 10px 0;
}

.editor-preview code {
    background: #f1f3f4;
    padding: 2px 4px;
    border-radius: 3px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
}

.editor-preview pre code {
    background: none;
    padding: 0;
}

/* 锚点高亮样式 */
.anchor-highlight {
    background-color: #fff3cd !important;
    border: 2px solid #ffc107 !important;
    border-radius: 4px !important;
    padding: 4px 8px !important;
    margin: 4px 0 !important;
    box-shadow: 0 2px 8px rgba(255, 193, 7, 0.3) !important;
    animation: anchorGlow 2s ease-in-out infinite alternate !important;
}

@keyframes anchorGlow {
    from {
        box-shadow: 0 2px 8px rgba(255, 193, 7, 0.3);
    }
    to {
        box-shadow: 0 4px 16px rgba(255, 193, 7, 0.6);
    }
}

/* 增强预览区域的滚动性能 */
.editor-preview {
    scroll-behavior: smooth;
}

/* 链接悬停样式增强 */
.editor-preview a[data-anchor] {
    color: #007bff;
    text-decoration: underline;
    cursor: pointer;
    transition: color 0.2s ease;
}

.editor-preview a[data-anchor]:hover {
    color: #0056b3;
    text-decoration: underline;
}

/* 标题ID自动生成（用于锚点） */
.editor-preview h1,
.editor-preview h2,
.editor-preview h3,
.editor-preview h4,
.editor-preview h5,
.editor-preview h6 {
    scroll-margin-top: 20px;
}

/* 自动完成建议框样式 */
.autocomplete-suggestions {
    max-width: 300px;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.autocomplete-suggestions div:last-child {
    border-bottom: none;
}

/* 编辑器整体样式优化 */
.editor-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
    padding: 4px;
}

.editor-toolbar button {
    min-width: 32px;
    min-height: 32px;
    max-width: 40px;
    padding: 4px 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #ddd;
    background: #f8f9fa;
    cursor: pointer;
    border-radius: 4px;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.editor-toolbar button i {
    font-size: 14px;
    line-height: 1;
}

.editor-toolbar button:hover {
    background: #e9ecef;
    border-color: #adb5bd;
}

.editor-toolbar button.active {
    background: #007bff;
    color: white;
    border-color: #007bff;
}

.editor-toolbar .separator,
.editor-toolbar span[data-separator="true"] {
    width: 1px;
    height: 24px;
    background: #dee2e6;
    margin: 0 4px;
    align-self: center;
    flex-shrink: 0;
}

/* 响应式设计 */
@media (max-width: 768px) {
    .editor-toolbar {
        gap: 1px;
        padding: 2px;
    }
    
    .editor-toolbar button {
        min-width: 28px;
        min-height: 28px;
        padding: 2px 6px;
    }
    
    .editor-toolbar button i {
        font-size: 12px;
    }
    
    .autocomplete-suggestions {
        max-width: 250px;
        font-size: 13px;
    }
    
    .emoji-picker-grid {
        grid-template-columns: repeat(6, 1fr);
    }
}

/* 确保工具栏不换行 */
@media (min-width: 769px) {
    .editor-toolbar {
        flex-wrap: nowrap;
        overflow-x: auto;
    }
}
</style>

<script>
// 更新工具栏按钮状态
function updateToolbarState(editor) {
    const cm = editor.codemirror;
    const toolbar = editor.toolbarElement;
    if (!toolbar) return;
    
    // 获取当前光标位置的行内容
    const cursor = cm.getCursor();
    const line = cm.getLine(cursor.line);
    const lineText = line.trim();
    
    // 检查当前行的类型
    const isUnorderedList = /^[-*+]/.test(lineText);
    const isOrderedList = /^\d+\./.test(lineText);
    const isQuote = /^>/.test(lineText);
    const isHeading = /^#+/.test(lineText);
    
    // 重置所有按钮状态
    const buttons = toolbar.querySelectorAll('button');
    buttons.forEach(button => {
        button.classList.remove('active');
        button.style.background = '#f8f9fa';
        button.style.color = '';
        button.style.borderColor = '#ddd';
    });
    
    // 根据当前行类型设置按钮状态
    buttons.forEach(button => {
        const className = button.className;
        
        // 检查是否为无序列表按钮
        if (isUnorderedList && className.includes('unordered-list')) {
            button.classList.add('active');
            button.style.background = '#007bff';
            button.style.color = 'white';
            button.style.borderColor = '#007bff';
        }
        // 检查是否为有序列表按钮
        else if (isOrderedList && className.includes('ordered-list')) {
            button.classList.add('active');
            button.style.background = '#007bff';
            button.style.color = 'white';
            button.style.borderColor = '#007bff';
        }
        // 检查是否为引用按钮
        else if (isQuote && className.includes('quote')) {
            button.classList.add('active');
            button.style.background = '#007bff';
            button.style.color = 'white';
            button.style.borderColor = '#007bff';
        }
        // 检查是否为标题按钮
        else if (isHeading && className.includes('heading')) {
            button.classList.add('active');
            button.style.background = '#007bff';
            button.style.color = 'white';
            button.style.borderColor = '#007bff';
        }
    });
}

// 检查内容长度并显示提醒
function checkContentLength(editor) {
    const content = editor.value();
    const maxLength = 16000000; // LONGTEXT的最大长度约16MB
    
    if (content.length > maxLength * 0.9) { // 超过90%时提醒
        showContentWarning(content.length, maxLength);
    }
}

// 生成标题ID以支持锚点跳转
function generateHeaderIds(editor) {
    const preview = document.querySelector('.editor-preview');
    if (!preview) return;
    
    const headings = preview.querySelectorAll('h1, h2, h3, h4, h5, h6');
    const usedIds = new Set();
    
    headings.forEach((heading, index) => {
        let id = heading.getAttribute('id');
        
        // 如果没有ID，生成一个
        if (!id) {
            // 生成基础ID
            let baseId = heading.textContent
                .toLowerCase()
                .replace(/[^\w\s-]/g, '') // 移除特殊字符
                .replace(/\s+/g, '-') // 空格替换为连字符
                .replace(/-+/g, '-') // 多个连字符合并为一个
                .trim();
            
            // 如果基础ID为空，使用通用ID
            if (!baseId) {
                baseId = `heading-${index + 1}`;
            }
            
            // 确保ID唯一
            let finalId = baseId;
            let counter = 1;
            while (usedIds.has(finalId)) {
                finalId = `${baseId}-${counter}`;
                counter++;
            }
            
            usedIds.add(finalId);
            heading.setAttribute('id', finalId);
        }
    });
}

// 处理锚点点击
function handleAnchorClick(anchorId) {
    // 在预览区域中查找目标元素
    const preview = document.querySelector('.editor-preview');
    if (!preview) {
        console.error('预览区域未找到');
        return;
    }
    
    // 移除现有的高亮
    const existingHighlights = preview.querySelectorAll('.anchor-highlight');
    existingHighlights.forEach(el => el.classList.remove('anchor-highlight'));
    
    // 尝试多种查找方式
    let targetElement = null;
    
    // 1. 通过ID查找
    targetElement = preview.querySelector(`#${anchorId}`);
    
    // 2. 如果找不到，尝试通过name属性查找
    if (!targetElement) {
        targetElement = preview.querySelector(`[name="${anchorId}"]`);
    }
    
    // 3. 如果还找不到，尝试通过文本内容查找标题
    if (!targetElement) {
        const decodedAnchor = decodeURIComponent(anchorId).replace(/-/g, ' ').toLowerCase();
        const headings = preview.querySelectorAll('h1, h2, h3, h4, h5, h6');
        
        for (let heading of headings) {
            const headingText = heading.textContent.toLowerCase().replace(/\s+/g, '-');
            const headingId = heading.getAttribute('id') || headingText;
            
            if (headingId === decodedAnchor || headingId === anchorId) {
                targetElement = heading;
                break;
            }
        }
    }
    
    // 4. 最后尝试通过包含文本查找
    if (!targetElement) {
        const allElements = preview.querySelectorAll('*');
        const searchTerms = decodeURIComponent(anchorId).split('-').filter(term => term.length > 0);
        
        for (let element of allElements) {
            const elementText = element.textContent.toLowerCase();
            if (searchTerms.every(term => elementText.includes(term))) {
                targetElement = element;
                break;
            }
        }
    }
    
    if (targetElement) {
        // 添加高亮效果
        targetElement.classList.add('anchor-highlight');
        targetElement.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
        
        // 3秒后移除高亮
        setTimeout(() => {
            targetElement.classList.remove('anchor-highlight');
        }, 3000);
        
        console.log('成功定位到锚点:', anchorId);
    } else {
        console.warn('未找到锚点目标:', anchorId);
        
        // 尝试滚动到相似的内容
        const fallbackElements = preview.querySelectorAll('h1, h2, h3, h4, h5, h6, p');
        const anchorText = decodeURIComponent(anchorId).toLowerCase();
        
        for (let element of fallbackElements) {
            if (element.textContent.toLowerCase().includes(anchorText)) {
                element.classList.add('anchor-highlight');
                element.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
                
                setTimeout(() => {
                    element.classList.remove('anchor-highlight');
                }, 3000);
                
                console.log('使用备用方案定位到相似内容:', element.textContent);
                return;
            }
        }
    }
}

// 显示内容长度警告
function showContentWarning(currentLength, maxLength) {
    // 检查是否已有警告框
    let warning = document.querySelector('.content-length-warning');
    if (!warning) {
        warning = document.createElement('div');
        warning.className = 'content-length-warning';
        warning.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 12px 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10000;
            max-width: 300px;
            font-size: 14px;
            color: #856404;
        `;
        document.body.appendChild(warning);
        
        // 10秒后自动移除
        setTimeout(() => {
            if (warning.parentNode) {
                warning.parentNode.removeChild(warning);
            }
        }, 10000);
    }
    
    warning.innerHTML = `
        <div style="display: flex; align-items: center; margin-bottom: 8px;">
            <i class="bi bi-exclamation-triangle-fill" style="margin-right: 8px; color: #f39c12;"></i>
            <strong>内容长度警告</strong>
        </div>
        <div>当前内容长度: ${currentLength.toLocaleString()} 字符</div>
        <div>建议最大长度: ${(maxLength * 0.9).toLocaleString()} 字符</div>
        <div style="margin-top: 8px; font-size: 12px;">
            如需更长内容，请联系管理员优化数据库配置。
        </div>
        <button onclick="this.parentElement.parentElement.remove()" style="
            margin-top: 8px;
            padding: 4px 8px;
            background: #f39c12;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        ">关闭</button>
    `;
}

// 初始化 Markdown 编辑器
function initMarkdownEditor(textareaId) {
    const textarea = document.getElementById(textareaId);
    if (!textarea) return null;
    
    const easyMDE = new EasyMDE({
        element: textarea,
        spellChecker: false,
        placeholder: '支持 Markdown 语法...',
        toolbar: [
            // 文字格式化
            'bold', 'italic', 'strikethrough', '|',
            'heading', '|',
            
            // 结构化内容
            'quote', 'code', '|',
            'unordered-list', 'ordered-list', '|',
            
            // 链接和媒体
            'link', '|',
            {
                name: 'image',
                action: function customImageFunction(editor) {
                    uploadImage(editor);
                },
                className: 'bi bi-image',
                title: '上传图片'
            },
            {
                name: 'image-compressor',
                action: function() {
                    window.open('/vendor/public/image_compressor/index.html', '_blank');
                },
                className: 'bi bi-scissors',
                title: '图片压缩工具'
            },
            {
                name: 'video',
                action: function customVideoFunction(editor) {
                    uploadVideo(editor);
                },
                className: 'bi bi-camera-video',
                title: '上传视频'
            },
            {
                name: 'file',
                action: function customFileFunction(editor) {
                    uploadFile(editor);
                },
                className: 'bi bi-file-earmark',
                title: '上传文件'
            },
            '|',
            
            // 高级功能
            {
                name: 'table',
                action: function customTableFunction(editor) {
                    insertTable(editor);
                },
                className: 'bi bi-table',
                title: '插入表格'
            },
            {
                name: 'emoji',
                action: function customEmojiFunction(editor) {
                    insertEmoji(editor);
                },
                className: 'bi bi-emoji-smile',
                title: '插入表情符号'
            },
            {
                name: 'math',
                action: function customMathFunction(editor) {
                    insertMathFormula(editor);
                },
                className: 'bi bi-calculator',
                title: '插入数学公式'
            },
            {
                name: 'horizontal-rule',
                action: EasyMDE.drawHorizontalRule,
                className: 'bi bi-dash-lg',
                title: '插入分隔线'
            },
            '|',
            {
                name: 'color',
                action: function customColorFunction(editor) {
                    insertColorText(editor);
                },
                className: 'bi bi-palette',
                title: '彩色文字'
            },
            
            // 编辑操作
            {
                name: 'undo',
                action: EasyMDE.undo,
                className: 'bi bi-arrow-counterclockwise',
                title: '撤销'
            },
            {
                name: 'redo',
                action: EasyMDE.redo,
                className: 'bi bi-arrow-clockwise',
                title: '重做'
            },
            '|',
            {
                name: 'clean-block',
                action: EasyMDE.cleanBlock,
                className: 'bi bi-eraser',
                title: '清理块格式'
            },
            '|',
            
            // 视图模式
            'preview', 'side-by-side', 'fullscreen', '|',
            'guide'
        ],
        shortcuts: {
            'toggleBold': 'Ctrl-B',
            'toggleItalic': 'Ctrl-I',
            'drawLink': 'Ctrl-K',
            'toggleHeadingSmaller': 'Ctrl-H',
            'toggleCodeBlock': 'Ctrl-Shift-C',
            'togglePreview': 'Ctrl-P',
            'toggleSideBySide': 'Ctrl-Shift-P',
            'toggleFullscreen': 'F11',
            'cleanBlock': 'Ctrl-E',
            'drawTable': 'Ctrl-T',
            'insertEmoji': 'Ctrl-Shift-E',
            'insertMath': 'Ctrl-Shift-M',
            'uploadImage': 'Ctrl-Shift-I',
            'uploadFile': 'Ctrl-Shift-U'
        },
        previewRender: function(plainText) {
            // 在 Markdown 解析前，将 <color:xxx>...</color> 替换为占位符，避免被 marked 转义或吞掉
            const colorNames = {red:'#e74c3c',blue:'#3498db',green:'#2ecc71',orange:'#e67e22',purple:'#9b59b6',pink:'#e91e63',yellow:'#f1c40f',cyan:'#00bcd4',white:'#ffffff',black:'#333333',gray:'#95a5a6',brown:'#8b4513',gold:'#ffd700',indigo:'#3f51b5',teal:'#009688',lime:'#8bc34a',coral:'#ff7f50',salmon:'#fa8072',crimson:'#dc143c',navy:'#000080'};
            const colorPlaceholders = [];
            let preProcessed = plainText;
            preProcessed = preProcessed.replace(/<color:([^>]+)>([\s\S]*?)<\/color>/gi, function(match, color, text) {
                const resolvedColor = colorNames[color.toLowerCase()] || color;
                const placeholder = '%%COLOR_' + colorPlaceholders.length + '%%';
                colorPlaceholders.push('<span style="color:' + resolvedColor + ';font-weight:inherit">' + text + '</span>');
                return placeholder;
            });

            // 增强的 Markdown 预览
            let html = this.parent.markdown(preProcessed);

            // 将占位符还原为彩色 span
            colorPlaceholders.forEach(function(span, i) {
                html = html.replace('<p>' + '%%COLOR_' + i + '%%' + '</p>', span);
                html = html.replace('%%COLOR_' + i + '%%', span);
            });
            
            // 处理数学公式
            html = html.replace(/\$\$(.*?)\$\$/gs, '<div class="math-block">$1</div>');
            html = html.replace(/\$(.*?)\$/g, '<span class="math-inline">$1</span>');
            
            // 处理任务列表
            html = html.replace(/- \[ \] (.*)/g, '<li class="task-list-item"><input type="checkbox" disabled> $1</li>');
            html = html.replace(/- \[x\] (.*)/g, '<li class="task-list-item"><input type="checkbox" checked disabled> $1</li>');
            
            // 处理锚点跳转 - 增强链接点击处理
            html = html.replace(/<a\s+(?:[^>]*?\s+)?href="(#[^"]*)"(?:[^>]*?)>/gi, function(match, href) {
                const cleanHref = href.replace(/^#/, '');
                return `<a href="${href}" onclick="handleAnchorClick('${cleanHref}'); return false;" data-anchor="${cleanHref}">`;
            });
            
            return html;
        },
        renderingConfig: {
            codeSyntaxHighlighting: true,
            sanitize: false,
            singleLineBreaks: false,
            markedOptions: {
                gfm: true,
                tables: true,
                breaks: false,
                pedantic: false,
                sanitize: false,
                smartLists: true,
                smartypants: false
            }
        },
        insertTexts: {
            horizontalRule: ['', '\n\n-----\n\n'],
            image: ['![', '](https://)'],
            link: ['[', '](https://)'],
            table: ['', '\n\n| Column 1 | Column 2 | Column 3 |\n| -------- | -------- | -------- |\n| Text     | Text     | Text     |\n\n']
        },
        promptTexts: {
            image: '图片链接',
            link: '链接地址'
        }
    });
    
    // 添加自定义快捷键
    const cm = easyMDE.codemirror;
    
    // 优化工具栏布局
    setTimeout(() => {
        const toolbar = easyMDE.toolbarElement;
        if (toolbar) {
            // 确保工具栏使用flexbox布局
            toolbar.style.display = 'flex';
            toolbar.style.flexWrap = 'nowrap';
            toolbar.style.alignItems = 'center';
            toolbar.style.gap = '2px';
            
            // 移除所有空的或隐藏的元素
            const allChildren = Array.from(toolbar.children);
            allChildren.forEach(child => {
                // 移除空的span元素
                if (child.tagName === 'SPAN' && child.textContent.trim() === '' && !child.classList.contains('separator')) {
                    child.remove();
                }
                // 移除没有内容的按钮
                if (child.tagName === 'BUTTON' && !child.innerHTML.trim() && !child.querySelector('i') && !child.querySelector('svg')) {
                    child.remove();
                }
            });
            
            // 处理分隔符
            const separators = toolbar.querySelectorAll('span, button');
            separators.forEach(element => {
                if (element.textContent === '|' || element.getAttribute('data-separator') === 'true') {
                    element.className = 'separator';
                    element.style.width = '1px';
                    element.style.height = '24px';
                    element.style.background = '#dee2e6';
                    element.style.margin = '0 4px';
                    element.style.border = 'none';
                    element.style.cursor = 'default';
                    element.style.pointerEvents = 'none';
                    element.style.padding = '0';
                    element.style.minWidth = 'auto';
                    element.style.maxWidth = 'auto';
                }
            });
            
            // 确保按钮尺寸一致
            const buttons = toolbar.querySelectorAll('button:not(.separator)');
            buttons.forEach(button => {
                if (!button.classList.contains('separator')) {
                    // 设置基础样式
                    button.style.minWidth = '32px';
                    button.style.maxWidth = '40px';
                    button.style.height = '32px';
                    button.style.padding = '4px 8px';
                    button.style.display = 'inline-flex';
                    button.style.alignItems = 'center';
                    button.style.justifyContent = 'center';
                    button.style.borderRadius = '4px';
                    button.style.border = '1px solid #ddd';
                    button.style.background = '#f8f9fa';
                    button.style.cursor = 'pointer';
                    button.style.margin = '0';
                    button.style.flexShrink = '0';
                    button.style.whiteSpace = 'nowrap';
                    button.style.overflow = 'hidden';
                    button.style.textOverflow = 'ellipsis';
                    button.style.transition = 'all 0.2s ease';
                    
                    // 设置悬停效果（如果还没有的话）
                    if (!button.hasAttribute('data-hover-added')) {
                        button.addEventListener('mouseenter', function() {
                            if (!this.classList.contains('active')) {
                                this.style.background = '#e9ecef';
                                this.style.borderColor = '#adb5bd';
                            }
                        });
                        
                        button.addEventListener('mouseleave', function() {
                            if (!this.classList.contains('active')) {
                                this.style.background = '#f8f9fa';
                                this.style.borderColor = '#ddd';
                            }
                        });
                        
                        button.setAttribute('data-hover-added', 'true');
                    }
                    
                    // 确保图标正确显示
                    const icon = button.querySelector('i');
                    if (icon) {
                        icon.style.fontSize = '14px';
                        icon.style.lineHeight = '1';
                        icon.style.pointerEvents = 'none';
                    }
                }
            });
        }
    }, 100);
    
    // Ctrl+T 插入表格
    cm.addKeyMap({
        'Ctrl-T': function() {
            insertTable(easyMDE);
        }
    });
    
    // Ctrl+Shift+E 插入表情
    cm.addKeyMap({
        'Ctrl-Shift-E': function() {
            insertEmoji(easyMDE);
        }
    });
    
    // Ctrl+Shift+M 插入数学公式
    cm.addKeyMap({
        'Ctrl-Shift-M': function() {
            insertMathFormula(easyMDE);
        }
    });
    
    // Tab 键自动补全
    let autoCompleteTimeout;
    cm.on('change', function() {
        clearTimeout(autoCompleteTimeout);
        autoCompleteTimeout = setTimeout(() => {
            autoComplete(cm);
        }, 200); // 延迟200ms触发自动补全，避免频繁触发
    });
    
    // ESC键关闭自动补全
    cm.addKeyMap({
        'Esc': function() {
            const existing = document.querySelector('.autocomplete-suggestions');
            if (existing) {
                existing.remove();
            }
        }
    });
    
    // 编辑器失去焦点时清理自动补全
    cm.on('blur', function() {
        setTimeout(() => {
            const existing = document.querySelector('.autocomplete-suggestions');
            if (existing) {
                existing.remove();
            }
        }, 150); // 延迟清理，允许点击建议项
    });
    
    // 监听光标位置变化，更新工具栏状态
    cm.on('cursorActivity', function() {
        updateToolbarState(easyMDE);
    });
    
    // 监听文本变化，延迟更新工具栏状态
    let changeTimeout;
    cm.on('change', function() {
        clearTimeout(changeTimeout);
        changeTimeout = setTimeout(() => {
            updateToolbarState(easyMDE);
            // 检查内容长度
            checkContentLength(easyMDE);
        }, 100);
    });
    
    // 初始化工具栏状态
    setTimeout(() => {
        updateToolbarState(easyMDE);
    }, 200);
    
    // 监听预览渲染完成，为标题生成ID
    easyMDE.codemirror.on('update', function() {
        setTimeout(() => {
            generateHeaderIds(easyMDE);
        }, 100);
    });
    
    return easyMDE;
}

// 自动补全功能
function autoComplete(cm) {
    const cursor = cm.getCursor();
    const line = cm.getLine(cursor.line);
    const textBefore = line.substring(0, cursor.ch);
    
    // 检查是否输入了特定的触发字符
    if (textBefore.endsWith(':')) {
        // 表情自动补全
        const emojiSuggestions = getEmojiSuggestions(textBefore);
        if (emojiSuggestions.length > 0) {
            showAutoComplete(cm, cursor, emojiSuggestions, 'emoji');
        }
    } else if (textBefore.endsWith('\\') || (textBefore.endsWith('$') && !textBefore.endsWith('$$'))) {
        // 数学公式自动补全
        const mathSuggestions = getMathSuggestions(textBefore);
        if (mathSuggestions.length > 0) {
            showAutoComplete(cm, cursor, mathSuggestions, 'math');
        }
    }
}

// 获取表情建议
function getEmojiSuggestions(text) {
    const emojiMap = {
        ':smile:': '😄',
        ':heart:': '❤️',
        ':thumbsup:': '👍',
        ':thumbsdown:': '👎',
        ':fire:': '🔥',
        ':star:': '⭐',
        ':check:': '✅',
        ':x:': '❌',
        ':warning:': '⚠️',
        ':info:': 'ℹ️',
        ':question:': '❓',
        ':exclamation:': '❗',
        ':thinking:': '🤔',
        ':cool:': '😎',
        ':wink:': '😉',
        ':happy:': '😊',
        ':sad:': '😢',
        ':angry:': '😠',
        ':cry:': '😭',
        ':laugh:': '😂'
    };
    
    const suggestions = [];
    for (const [key, value] of Object.entries(emojiMap)) {
        if (key.includes(text)) {
            suggestions.push({ text: key, display: value });
        }
    }
    return suggestions;
}

// 获取数学公式建议
function getMathSuggestions(text) {
    const mathCommands = [
        { cmd: '\\frac', display: '分数' },
        { cmd: '\\sqrt', display: '开方' },
        { cmd: '\\sum', display: '求和' },
        { cmd: '\\int', display: '积分' },
        { cmd: '\\lim', display: '极限' },
        { cmd: '\\alpha', display: 'α' },
        { cmd: '\\beta', display: 'β' },
        { cmd: '\\gamma', display: 'γ' },
        { cmd: '\\delta', display: 'δ' },
        { cmd: '\\epsilon', display: 'ε' },
        { cmd: '\\theta', display: 'θ' },
        { cmd: '\\lambda', display: 'λ' },
        { cmd: '\\mu', display: 'μ' },
        { cmd: '\\pi', display: 'π' },
        { cmd: '\\sigma', display: 'σ' },
        { cmd: '\\phi', display: 'φ' },
        { cmd: '\\omega', display: 'ω' },
        { cmd: '\\infty', display: '∞' },
        { cmd: '\\partial', display: '∂' },
        { cmd: '\\nabla', display: '∇' }
    ];
    
    const suggestions = [];
    const lastWord = text.split(/[\\$]/).pop();
    
    for (const cmd of mathCommands) {
        if (cmd.cmd.includes(lastWord)) {
            suggestions.push({ text: cmd.cmd, display: `${cmd.cmd} (${cmd.display})` });
        }
    }
    return suggestions;
}

// 显示自动完成建议
function showAutoComplete(cm, cursor, suggestions, type) {
    // 移除已存在的建议框
    const existing = document.querySelector('.autocomplete-suggestions');
    if (existing) {
        existing.remove();
    }
    
    if (suggestions.length === 0) return;
    
    // 创建建议框
    const suggestionsDiv = document.createElement('div');
    suggestionsDiv.className = 'autocomplete-suggestions';
    suggestionsDiv.style.cssText = `
        position: absolute;
        background: white;
        border: 1px solid #ddd;
        border-radius: 4px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        max-height: 200px;
        overflow-y: auto;
        z-index: 1000;
        font-size: 14px;
    `;
    
    suggestions.forEach((suggestion, index) => {
        const item = document.createElement('div');
        item.style.cssText = `
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
        `;
        
        if (type === 'emoji') {
            item.innerHTML = `${suggestion.display} ${suggestion.text}`;
        } else {
            item.textContent = suggestion.display;
        }
        
        item.addEventListener('click', () => {
            selectSuggestion(cm, cursor, suggestion, type);
            suggestionsDiv.remove();
        });
        
        item.addEventListener('mouseenter', () => {
            item.style.background = '#f5f5f5';
        });
        
        item.addEventListener('mouseleave', () => {
            item.style.background = 'white';
        });
        
        suggestionsDiv.appendChild(item);
    });
    
    // 定位建议框
    const coords = cm.cursorCoords(cursor);
    suggestionsDiv.style.left = coords.left + 'px';
    suggestionsDiv.style.top = (coords.bottom + 2) + 'px';
    
    document.body.appendChild(suggestionsDiv);
    
    // 点击外部关闭
    setTimeout(() => {
        document.addEventListener('click', function closeOnClick(e) {
            if (!suggestionsDiv.contains(e.target)) {
                suggestionsDiv.remove();
                document.removeEventListener('click', closeOnClick);
            }
        });
    }, 100);
}

// 选择建议项
function selectSuggestion(cm, cursor, suggestion, type) {
    const line = cm.getLine(cursor.line);
    const textBefore = line.substring(0, cursor.ch);
    
    let replaceStart = cursor.ch;
    if (type === 'emoji') {
        // 找到冒号的位置
        const lastColonIndex = textBefore.lastIndexOf(':');
        if (lastColonIndex !== -1) {
            replaceStart = lastColonIndex;
        }
        cm.replaceRange(suggestion.display, { line: cursor.line, ch: replaceStart }, cursor);
    } else if (type === 'math') {
        // 找到反斜杠或美元符号的位置
        const lastBackslashIndex = textBefore.lastIndexOf('\\');
        const lastDollarIndex = textBefore.lastIndexOf('$');
        replaceStart = Math.max(lastBackslashIndex, lastDollarIndex);
        cm.replaceRange(suggestion.text, { line: cursor.line, ch: replaceStart }, cursor);
    }
    
    cm.focus();
}

// 上传图片
function uploadImage(editor) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';

    input.onchange = function() {
        const file = input.files[0];
        if (!file) return;

        // 验证文件类型
        if (!file.type.startsWith('image/')) {
            alert('请选择图片文件');
            return;
        }

        // 验证文件大小 (5MB)
        if (file.size > 5 * 1024 * 1024) {
            alert('图片大小不能超过 5MB');
            return;
        }

        uploadFileToServer(editor, file, 'image', '/admin/upload_image.php',
            (data) => {
                // 直接插入本地URL
                const markdown = `![${file.name}](${data.url})`;
                const cm = editor.codemirror;
                const pos = cm.getCursor();
                cm.replaceRange(markdown, pos);
            }, {source: 'posts'});
    };

    input.click();
}

// 上传视频
function uploadVideo(editor) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'video/*';
    
    input.onchange = function() {
        const file = input.files[0];
        if (!file) return;
        
        // 验证文件类型
        if (!file.type.startsWith('video/')) {
            alert('请选择视频文件');
            return;
        }
        
        // 验证文件大小 (100MB)
        if (file.size > 100 * 1024 * 1024) {
            alert('视频大小不能超过 100MB');
            return;
        }
        
        uploadFileToServer(editor, file, 'video', '/admin/upload_video.php', 
            (data) => {
                const markdown = `<video controls width="100%">\n  <source src="${data.url}" type="video/mp4">\n  您的浏览器不支持视频播放\n</video>`;
                const cm = editor.codemirror;
                const pos = cm.getCursor();
                cm.replaceRange(markdown, pos);
            }, {source: 'posts'});
    };
    
    input.click();
}

// 上传文件
function uploadFile(editor) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar,.7z,.json,.csv';
    
    input.onchange = function() {
        const file = input.files[0];
        if (!file) return;
        
        // 验证文件大小 (50MB)
        if (file.size > 50 * 1024 * 1024) {
            alert('文件大小不能超过 50MB');
            return;
        }
        
        uploadFileToServer(editor, file, 'file', '/admin/upload_file.php', 
            (data) => {
                const markdown = `[📎 ${data.originalName}](${data.url})`;
                const cm = editor.codemirror;
                const pos = cm.getCursor();
                cm.replaceRange(markdown, pos);
            });
    };
    
    input.click();
}

// 创建上传进度条
function createProgressOverlay(fileName, fileSize) {
    const overlay = document.createElement('div');
    overlay.className = 'upload-progress-overlay';
    overlay.innerHTML = `
        <div class="upload-progress-container">
            <div class="upload-progress-header">
                <div class="upload-progress-icon">📤</div>
                <div class="upload-progress-info">
                    <h5>正在上传文件</h5>
                    <p>${fileName} (${formatFileSize(fileSize)})</p>
                </div>
            </div>
            <div class="upload-progress-bar-container">
                <div class="upload-progress-bar" style="width: 0%"></div>
            </div>
            <div class="upload-progress-text">
                <span class="progress-percent">0%</span>
                <span class="progress-speed">准备中...</span>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    return overlay;
}

// 格式化文件大小
function formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}

// 格式化上传速度
function formatSpeed(bytesPerSecond) {
    return formatFileSize(bytesPerSecond) + '/s';
}

// 更新进度条
function updateProgress(overlay, percent, speed) {
    const progressBar = overlay.querySelector('.upload-progress-bar');
    const progressPercent = overlay.querySelector('.progress-percent');
    const progressSpeed = overlay.querySelector('.progress-speed');
    
    if (progressBar) progressBar.style.width = percent + '%';
    if (progressPercent) progressPercent.textContent = Math.round(percent) + '%';
    if (progressSpeed && speed !== null) {
        progressSpeed.textContent = formatSpeed(speed);
    }
}

// 显示上传成功
function showUploadSuccess(overlay, message = '上传成功！') {
    const container = overlay.querySelector('.upload-progress-container');
    const icon = overlay.querySelector('.upload-progress-icon');
    const title = overlay.querySelector('.upload-progress-info h5');
    const progressText = overlay.querySelector('.upload-progress-text');
    
    if (icon) icon.textContent = '✓';
    if (icon) icon.style.background = '#e8f5e9';
    if (title) title.textContent = message;
    if (title) title.className = 'upload-progress-success';
    if (progressText) progressText.innerHTML = '<span class="upload-progress-success">完成</span>';
    
    setTimeout(() => {
        overlay.remove();
    }, 1500);
}

// 显示上传失败
function showUploadError(overlay, message = '上传失败') {
    const icon = overlay.querySelector('.upload-progress-icon');
    const title = overlay.querySelector('.upload-progress-info h5');
    const progressText = overlay.querySelector('.upload-progress-text');
    
    if (icon) icon.textContent = '✗';
    if (icon) icon.style.background = '#ffebee';
    if (title) title.textContent = message;
    if (title) title.className = 'upload-progress-error';
    if (progressText) progressText.innerHTML = '<span class="upload-progress-error">失败</span>';
    
    setTimeout(() => {
        overlay.remove();
    }, 3000);
}

// 插入表格
function insertTable(editor) {
    const cm = editor.codemirror;
    const selection = cm.getSelection();
    
    // 创建一个简单的表格模板
    const tableTemplate = '\n| 标题1 | 标题2 | 标题3 |\n|-------|-------|-------|\n| 内容1 | 内容2 | 内容3 |\n| 内容4 | 内容5 | 内容6 |\n';
    
    if (selection) {
        // 如果有选中文本，将其放在表格中
        const lines = selection.split('\n');
        const headers = lines[0].split(/[,\t|]/).map(h => h.trim()).slice(0, 3);
        const rows = lines.slice(1).map(line => 
            line.split(/[,\t|]/).map(cell => cell.trim()).slice(0, 3).join(' | ')
        );
        
        let table = '\n| ' + headers.join(' | ') + ' |\n';
        table += '|' + headers.map(() => '-------').join('|') + '|\n';
        rows.forEach(row => {
            if (row.trim()) {
                table += '| ' + row + ' |\n';
            }
        });
        
        cm.replaceSelection(table);
    } else {
        cm.replaceSelection(tableTemplate);
    }
}

// 插入彩色文字
function insertColorText(editor) {
    const cm = editor.codemirror;
    const selection = cm.getSelection();
    
    const presetColors = [
        { name: '红色', value: 'red', hex: '#e74c3c' },
        { name: '橙色', value: 'orange', hex: '#e67e22' },
        { name: '黄色', value: 'yellow', hex: '#f1c40f' },
        { name: '绿色', value: 'green', hex: '#2ecc71' },
        { name: '青色', value: 'cyan', hex: '#00bcd4' },
        { name: '蓝色', value: 'blue', hex: '#3498db' },
        { name: '紫色', value: 'purple', hex: '#9b59b6' },
        { name: '粉色', value: 'pink', hex: '#e91e63' },
        { name: '金色', value: 'gold', hex: '#ffd700' },
        { name: '珊瑚', value: 'coral', hex: '#ff7f50' },
        { name: '靛蓝', value: 'indigo', hex: '#3f51b5' },
        { name: '薄荷', value: 'teal', hex: '#009688' },
        { name: '草绿', value: 'lime', hex: '#8bc34a' },
        { name: '深红', value: 'crimson', hex: '#dc143c' },
        { name: '棕色', value: 'brown', hex: '#8b4513' },
        { name: '灰色', value: 'gray', hex: '#95a5a6' }
    ];
    
    // 创建颜色选择器
    const picker = document.createElement('div');
    picker.style.cssText = `
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        min-width: 320px;
    `;
    
    let html = '<div style="margin-bottom:12px;font-weight:600;font-size:15px;">选择文字颜色</div>';
    
    // 预设颜色网格
    html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px;">';
    presetColors.forEach(c => {
        html += `<div class="color-preset-item" data-color="${c.value}" style="
            display:flex;align-items:center;gap:6px;padding:6px 10px;
            border-radius:6px;cursor:pointer;border:1px solid #eee;
            transition:all 0.2s;font-size:13px;
        ">
            <span style="width:18px;height:18px;border-radius:50%;background:${c.hex};display:inline-block;border:1px solid rgba(0,0,0,0.1);flex-shrink:0;"></span>
            <span>${c.name}</span>
        </div>`;
    });
    html += '</div>';
    
    // 自定义颜色输入
    html += `<div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;padding-top:8px;border-top:1px solid #eee;">
        <label style="font-size:13px;white-space:nowrap;">自定义颜色:</label>
        <input type="color" id="customColorInput" value="#e74c3c" style="width:40px;height:30px;padding:0;border:none;cursor:pointer;">
        <input type="text" id="customColorText" placeholder="#ff6600 或 orange" style="flex:1;padding:4px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px;">
        <button type="button" id="applyCustomColor" style="padding:4px 12px;background:#0d6efd;color:white;border:none;border-radius:4px;cursor:pointer;font-size:13px;">应用</button>
    </div>`;
    
    // 取消按钮
    html += `<div style="text-align:right;">
        <button type="button" id="cancelColorPicker" style="padding:6px 16px;background:#6c757d;color:white;border:none;border-radius:4px;cursor:pointer;">取消</button>
    </div>`;
    
    picker.innerHTML = html;
    document.body.appendChild(picker);
    
    // 遮罩层
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.3);z-index:9999;';
    document.body.appendChild(overlay);
    
    function closePicker() {
        picker.remove();
        overlay.remove();
    }
    
    function applyColor(color) {
        const text = selection || '彩色文字';
        const before = `<color:${color}>`;
        const after = '</color>';
        
        if (selection) {
            cm.replaceSelection(before + selection + after);
        } else {
            const cursor = cm.getCursor();
            cm.replaceRange(before + text + after, cursor);
        }
        cm.focus();
        closePicker();
    }
    
    // 预设颜色点击
    picker.querySelectorAll('.color-preset-item').forEach(item => {
        item.addEventListener('mouseenter', () => {
            item.style.background = '#f0f0f0';
            item.style.transform = 'scale(1.02)';
        });
        item.addEventListener('mouseleave', () => {
            item.style.background = '';
            item.style.transform = '';
        });
        item.addEventListener('click', () => {
            applyColor(item.dataset.color);
        });
    });
    
    // 自定义颜色应用
    picker.querySelector('#applyCustomColor').addEventListener('click', () => {
        const colorText = picker.querySelector('#customColorText').value.trim();
        const colorValue = picker.querySelector('#customColorInput').value;
        if (colorText) {
            applyColor(colorText);
        } else {
            applyColor(colorValue);
        }
    });
    
    // 颜色选择器同步
    picker.querySelector('#customColorInput').addEventListener('input', (e) => {
        picker.querySelector('#customColorText').value = e.target.value;
    });
    
    // 取消
    picker.querySelector('#cancelColorPicker').addEventListener('click', closePicker);
    overlay.addEventListener('click', closePicker);
}

// 插入表情符号
function insertEmoji(editor) {
    const cm = editor.codemirror;
    const emojis = [
        '😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣',
        '😊', '😇', '🙂', '🙃', '😉', '😌', '😍', '🥰',
        '😘', '😗', '😙', '😚', '😋', '😛', '😜', '🤪',
        '🤔', '🤭', '🤫', '🤗', '🤩', '🥳', '😎', '🤓',
        '🧐', '😕', '😟', '🙁', '😮', '😯', '😲', '😳',
        '🥺', '😢', '😭', '😤', '😠', '😡', '🤬', '🤯',
        '😨', '😰', '😱', '😥', '😓', '🤗', '🤗', '🤗',
        '👍', '👎', '👌', '✌️', '🤞', '🤟', '🤘', '🤙',
        '👏', '🙌', '👐', '🤲', '🤝', '🙏', '💪', '✨',
        '🔥', '💥', '💢', '💨', '💦', '💧', '💤', '💨',
        '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '💔',
        '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝'
    ];
    
    // 创建表情选择器
    const emojiPicker = document.createElement('div');
    emojiPicker.style.cssText = `
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        max-width: 400px;
        max-height: 400px;
        overflow-y: auto;
    `;
    
    let emojiHtml = '<div style="margin-bottom: 15px;"><strong>选择表情符号：</strong></div>';
    emojiHtml += '<div style="display: grid; grid-template-columns: repeat(8, 1fr); gap: 5px; margin-bottom: 15px;">';
    
    emojis.forEach(emoji => {
        emojiHtml += `<button style="padding: 8px; font-size: 18px; border: 1px solid #ddd; background: white; cursor: pointer; border-radius: 4px;" onclick="selectEmoji('${emoji}')">${emoji}</button>`;
    });
    
    emojiHtml += '</div>';
    emojiHtml += '<div style="text-align: right;"><button onclick="closeEmojiPicker()" style="padding: 8px 16px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">取消</button></div>';
    
    emojiPicker.innerHTML = emojiHtml;
    
    // 添加到页面
    document.body.appendChild(emojiPicker);
    
    // 全局函数
    window.selectEmoji = function(emoji) {
        const pos = cm.getCursor();
        cm.replaceRange(emoji, pos);
        closeEmojiPicker();
    };
    
    window.closeEmojiPicker = function() {
        if (emojiPicker.parentNode) {
            emojiPicker.parentNode.removeChild(emojiPicker);
        }
    };
    
    // 点击外部关闭
    setTimeout(() => {
        document.addEventListener('click', function closeOnClick(e) {
            if (!emojiPicker.contains(e.target)) {
                closeEmojiPicker();
                document.removeEventListener('click', closeOnClick);
            }
        });
    }, 100);
}

// 插入数学公式
function insertMathFormula(editor) {
    const cm = editor.codemirror;
    const selection = cm.getSelection();
    
    // 创建数学公式选择器
    const mathDialog = document.createElement('div');
    mathDialog.style.cssText = `
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        width: 450px;
    `;
    
    const commonFormulas = [
        { name: '分数', template: '\\frac{a}{b}', display: '\u00b9\u2044\u2082' },
        { name: '开方', template: '\\sqrt{x}', display: '\u221a' },
        { name: '求和', template: '\\sum_{i=1}^{n}', display: '\u03a3' },
        { name: '积分', template: '\\int_{a}^{b}', display: '\u222b' },
        { name: '极限', template: '\\lim_{x\\to\\infty}', display: 'lim' },
        { name: '矩阵', template: '\\begin{matrix} a & b \\\\ c & d \\end{matrix}', display: '[a b; c d]' },
        { name: '上标', template: 'x^{2}', display: 'x\u00b2' },
        { name: '下标', template: 'x_{1}', display: 'x\u2081' }
    ];
    
    let html = '<div style="margin-bottom: 15px;"><strong>插入数学公式：</strong></div>';
    
    // 内联公式选项
    html += '<div style="margin-bottom: 10px;">';
    html += '<label><input type="radio" name="formula-type" value="inline" checked> 内联公式 ($...$)</label>';
    html += '<label style="margin-left: 15px;"><input type="radio" name="formula-type" value="block"> 块级公式 ($$...$$)</label>';
    html += '</div>';
    
    // 常用公式模板
    html += '<div style="margin-bottom: 15px;"><strong>常用公式模板：</strong></div>';
    html += '<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 15px;">';
    
    commonFormulas.forEach(formula => {
        html += `<button onclick="insertFormulaTemplate('${formula.template}')" style="padding: 8px; text-align: left; border: 1px solid #ddd; background: white; cursor: pointer; border-radius: 4px;">`;
        html += `<div style="font-weight: bold;">${formula.name}</div>`;
        html += `<div style="font-size: 12px; color: #666;">${formula.template}</div>`;
        html += `</button>`;
    });
    
    html += '</div>';
    
    // 自定义公式输入
    html += '<div style="margin-bottom: 15px;"><strong>自定义LaTeX公式：</strong></div>';
    html += '<textarea id="custom-formula" placeholder="输入LaTeX公式，例如：E = mc^2" style="width: 100%; height: 80px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-family: monospace;">';
    if (selection) {
        html += selection;
    }
    html += '</textarea>';
    
    // 按钮
    html += '<div style="text-align: right; margin-top: 15px;">';
    html += '<button onclick="insertCustomFormula()" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; margin-right: 8px;">插入</button>';
    html += '<button onclick="closeMathDialog()" style="padding: 8px 16px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">取消</button>';
    html += '</div>';
    
    mathDialog.innerHTML = html;
    document.body.appendChild(mathDialog);
    
    // 全局函数
    window.insertFormulaTemplate = function(template) {
        document.getElementById('custom-formula').value = template;
    };
    
    window.insertCustomFormula = function() {
        const formula = document.getElementById('custom-formula').value.trim();
        if (!formula) {
            alert('请输入公式');
            return;
        }
        
        const type = document.querySelector('input[name="formula-type"]:checked').value;
        let finalFormula;
        
        if (type === 'inline') {
            finalFormula = `$${formula}$`;
        } else {
            finalFormula = `\n$$${formula}$$\n`;
        }
        
        const pos = cm.getCursor();
        cm.replaceRange(finalFormula, pos);
        closeMathDialog();
    };
    
    window.closeMathDialog = function() {
        if (mathDialog.parentNode) {
            mathDialog.parentNode.removeChild(mathDialog);
        }
    };
    
    // 聚焦到文本框
    setTimeout(() => {
        const textarea = document.getElementById('custom-formula');
        if (textarea) {
            textarea.focus();
            textarea.select();
        }
    }, 100);
}

// 通用上传函数（带进度条）
function uploadFileToServer(editor, file, fieldName, uploadUrl, markdownGenerator, extraParams) {
    const cm = editor.codemirror;
    
    // 创建进度条
    const progressOverlay = createProgressOverlay(file.name, file.size);
    
    const formData = new FormData();
    formData.append(fieldName, file);
    
    // 附加额外参数（如 source）
    if (extraParams) {
        for (const [key, value] of Object.entries(extraParams)) {
            formData.append(key, value);
        }
    }
    
    // 使用 XMLHttpRequest 以支持进度监听
    const xhr = new XMLHttpRequest();
    
    let startTime = Date.now();
    let lastLoaded = 0;
    let lastTime = startTime;
    
    // 监听上传进度
    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            const percent = (e.loaded / e.total) * 100;
            
            // 计算上传速度
            const currentTime = Date.now();
            const timeDiff = (currentTime - lastTime) / 1000; // 秒
            const loadedDiff = e.loaded - lastLoaded;
            const speed = timeDiff > 0 ? loadedDiff / timeDiff : 0;
            
            updateProgress(progressOverlay, percent, speed);
            
            lastLoaded = e.loaded;
            lastTime = currentTime;
        }
    });
    
    // 监听完成
    xhr.addEventListener('load', () => {
        if (xhr.status === 200) {
            try {
                const data = JSON.parse(xhr.responseText);
                if (data.success) {
                    // 调用回调函数，让其自行处理（如插入编辑器）
                    markdownGenerator(data);
                    showUploadSuccess(progressOverlay);
                } else {
                    showUploadError(progressOverlay, data.error || '上传失败');
                }
            } catch (error) {
                console.error('解析响应错误:', error);
                showUploadError(progressOverlay, '服务器响应错误');
            }
        } else {
            showUploadError(progressOverlay, '上传失败 (HTTP ' + xhr.status + ')');
        }
    });
    
    // 监听错误
    xhr.addEventListener('error', () => {
        console.error('上传错误');
        showUploadError(progressOverlay, '网络错误，请重试');
    });
    
    // 监听中止
    xhr.addEventListener('abort', () => {
        showUploadError(progressOverlay, '上传已取消');
    });
    
    // 发送请求
    xhr.open('POST', uploadUrl);
    xhr.send(formData);
}
</script>
