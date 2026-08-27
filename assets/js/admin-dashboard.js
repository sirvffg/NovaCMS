(function (Vue, Chart) {
    'use strict';

    var mountPoint = document.getElementById('admin-dashboard-app');
    if (!mountPoint) return;

    if (!Vue || typeof Vue.createApp !== 'function') {
        mountPoint.removeAttribute('v-cloak');
        mountPoint.innerHTML = '<div class="alert alert-danger">仪表盘组件加载失败，请刷新页面后重试。</div>';
        return;
    }

    var config = window.NovaAdminDashboardConfig || {};
    var endpoints = config.endpoints || {};

    Vue.createApp({
        data: function () {
            return {
                stats: {
                    totalVisits: 0,
                    uniqueVisitors: 0,
                    todayVisits: 0,
                    todayUnique: 0,
                    totalPosts: 0,
                    totalComments: 0,
                    totalForms: 0,
                    pendingForms: 0
                },
                trends: null,
                popularPages: [],
                realtime: { visits: [], online_count: 0, total_today: 0 },
                statsLoading: true,
                popularLoading: true,
                realtimeLoading: true,
                popularError: '',
                realtimeError: '',
                globalError: '',
                refreshing: false,
                lastUpdated: '',
                chart: null,
                refreshTimer: null,
                    quickActions: [
                        { label: '发布文章', href: '/admin/posts.php?action=add', icon: 'bi-file-earmark-plus' },
                        { label: '管理评论', href: '/admin/comments.php', icon: 'bi-chat-left-dots' },
                        { label: '审核申请', href: '/admin/privacy_access.php', icon: 'bi-shield-check' },
                        { label: '网站设置', href: '/admin/config.php', icon: 'bi-sliders' },
                        { label: '用户管理', href: '/admin/admins.php', icon: 'bi-people' }
                    ]
            };
        },

        computed: {
            statCards: function () {
                return [
                    {
                        key: 'visits',
                        label: '累计访问量',
                        value: this.stats.totalVisits,
                        metaValue: '+' + this.formatNumber(this.stats.todayVisits),
                        metaLabel: '今日访问',
                        icon: 'bi-eye',
                        color: 'var(--admin-primary)'
                    },
                    {
                        key: 'visitors',
                        label: '独立访客',
                        value: this.stats.uniqueVisitors,
                        metaValue: '+' + this.formatNumber(this.stats.todayUnique),
                        metaLabel: '今日新增',
                        icon: 'bi-people',
                        color: 'var(--admin-success)'
                    },
                    {
                        key: 'content',
                        label: '已发布内容',
                        value: this.stats.totalPosts,
                        metaValue: this.formatNumber(this.stats.totalComments),
                        metaLabel: '条评论互动',
                        icon: 'bi-file-earmark-text',
                        color: 'var(--admin-info)'
                    },
                    {
                        key: 'forms',
                        label: '访问申请',
                        value: this.stats.totalForms,
                        metaValue: this.stats.pendingForms > 0 ? this.formatNumber(this.stats.pendingForms) : '0',
                        metaLabel: this.stats.pendingForms > 0 ? '项等待审核' : '项待处理',
                        icon: 'bi-inbox',
                        color: this.stats.pendingForms > 0 ? 'var(--admin-warning)' : 'var(--admin-accent)'
                    }
                ];
            },

            visibleVisits: function () {
                return Array.isArray(this.realtime.visits) ? this.realtime.visits.slice(0, 8) : [];
            }
        },

        methods: {
            cloneData: function (value) {
                return JSON.parse(JSON.stringify(value));
            },

            requestJson: async function (key, url) {
                if (config.mockData && Object.prototype.hasOwnProperty.call(config.mockData, key)) {
                    await new Promise(function (resolve) { window.setTimeout(resolve, 180); });
                    return this.cloneData(config.mockData[key]);
                }
                if (!url) throw new Error('接口地址未配置');

                var response = await fetch(url, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                var data;
                try {
                    data = await response.json();
                } catch (error) {
                    throw new Error('服务器返回了无法识别的数据');
                }
                if (!response.ok || data.error) {
                    if (response.status === 401 || response.status === 403) {
                        throw new Error('登录状态已失效，请重新登录');
                    }
                    throw new Error(data.error || '数据加载失败');
                }
                return data;
            },

            loadStats: async function () {
                this.statsLoading = true;
                try {
                    var data = await this.requestJson('stats', endpoints.stats);
                    Object.keys(this.stats).forEach(function (key) {
                        if (Object.prototype.hasOwnProperty.call(data, key)) this.stats[key] = Number(data[key]) || 0;
                    }, this);
                } finally {
                    this.statsLoading = false;
                }
            },

            loadTrends: async function () {
                var data = await this.requestJson('trends', endpoints.trends);
                this.trends = data;
                await this.$nextTick();
                this.renderTrendChart();
            },

            loadPopular: async function () {
                this.popularLoading = true;
                this.popularError = '';
                try {
                    var data = await this.requestJson('popular', endpoints.popular);
                    this.popularPages = Array.isArray(data.pages) ? data.pages : [];
                } catch (error) {
                    this.popularError = error.message || '热门页面加载失败';
                    throw error;
                } finally {
                    this.popularLoading = false;
                }
            },

            loadRealtime: async function () {
                this.realtimeLoading = true;
                this.realtimeError = '';
                try {
                    var data = await this.requestJson('realtime', endpoints.realtime);
                    this.realtime = {
                        visits: Array.isArray(data.visits) ? data.visits : [],
                        online_count: Number(data.online_count) || 0,
                        total_today: Number(data.total_today) || 0
                    };
                } catch (error) {
                    this.realtimeError = error.message || '实时访客加载失败';
                    throw error;
                } finally {
                    this.realtimeLoading = false;
                }
            },

            refreshAll: async function (notify) {
                if (this.refreshing) return;
                this.refreshing = true;
                this.globalError = '';

                var results = await Promise.allSettled([
                    this.loadStats(),
                    this.loadTrends(),
                    this.loadPopular(),
                    this.loadRealtime()
                ]);
                var failures = results.filter(function (result) { return result.status === 'rejected'; });

                if (failures.length === results.length) {
                    this.globalError = failures[0].reason && failures[0].reason.message
                        ? failures[0].reason.message
                        : '仪表盘数据暂时无法加载，请稍后重试';
                } else {
                    this.lastUpdated = new Intl.DateTimeFormat('zh-CN', {
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: false
                    }).format(new Date());
                }

                if (notify && window.showToast) {
                    if (failures.length === 0) window.showToast('仪表盘数据已更新', 'success');
                    else window.showToast('部分数据更新失败，请稍后重试', 'warning');
                }
                this.refreshing = false;
            },

            renderTrendChart: function () {
                if (!Chart || !this.$refs.trendChart || !this.trends || !this.trends.datasets) return;
                if (this.chart) this.chart.destroy();

                var styles = getComputedStyle(document.documentElement);
                var primary = styles.getPropertyValue('--admin-primary').trim() || '#5b5bd6';
                var success = styles.getPropertyValue('--admin-success').trim() || '#0f9f6e';
                var muted = styles.getPropertyValue('--admin-muted').trim() || '#667085';
                var border = styles.getPropertyValue('--admin-border').trim() || '#e5e9f0';
                var surface = styles.getPropertyValue('--admin-surface').trim() || '#ffffff';
                var context = this.$refs.trendChart.getContext('2d');
                var gradient = context.createLinearGradient(0, 0, 0, 290);
                gradient.addColorStop(0, this.withAlpha(primary, 0.22));
                gradient.addColorStop(1, this.withAlpha(primary, 0.01));

                this.chart = new Chart(context, {
                    type: 'line',
                    data: {
                        labels: Array.isArray(this.trends.labels) ? this.trends.labels : [],
                        datasets: [
                            {
                                label: '访问量',
                                data: this.trends.datasets.total_visits || [],
                                borderColor: primary,
                                backgroundColor: gradient,
                                pointBackgroundColor: surface,
                                pointBorderColor: primary,
                                pointBorderWidth: 2,
                                pointRadius: 2.5,
                                pointHoverRadius: 4,
                                borderWidth: 2.2,
                                fill: true,
                                tension: 0.36
                            },
                            {
                                label: '独立访客',
                                data: this.trends.datasets.unique_visitors || [],
                                borderColor: success,
                                backgroundColor: 'transparent',
                                pointBackgroundColor: surface,
                                pointBorderColor: success,
                                pointBorderWidth: 2,
                                pointRadius: 2.5,
                                pointHoverRadius: 4,
                                borderWidth: 2,
                                fill: false,
                                tension: 0.36
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        animation: { duration: 420 },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: styles.getPropertyValue('--admin-surface-raised').trim() || surface,
                                titleColor: styles.getPropertyValue('--admin-heading').trim() || '#101828',
                                bodyColor: muted,
                                borderColor: border,
                                borderWidth: 1,
                                padding: 11,
                                cornerRadius: 10,
                                displayColors: true,
                                boxPadding: 4
                            }
                        },
                        scales: {
                            x: {
                                border: { display: false },
                                grid: { display: false },
                                ticks: { color: muted, font: { size: 10, family: 'HarmonyOS Sans' } }
                            },
                            y: {
                                beginAtZero: true,
                                border: { display: false },
                                grid: { color: this.withAlpha(border, 0.72), drawTicks: false },
                                ticks: { color: muted, padding: 10, precision: 0, font: { size: 10, family: 'HarmonyOS Sans' } }
                            }
                        }
                    }
                });
            },

            withAlpha: function (color, alpha) {
                var value = String(color || '').trim();
                if (/^#[0-9a-f]{6}$/i.test(value)) {
                    var red = parseInt(value.slice(1, 3), 16);
                    var green = parseInt(value.slice(3, 5), 16);
                    var blue = parseInt(value.slice(5, 7), 16);
                    return 'rgba(' + red + ', ' + green + ', ' + blue + ', ' + alpha + ')';
                }
                return value.indexOf('rgb') === 0 ? value.replace(/\)$/, ', ' + alpha + ')').replace('rgb(', 'rgba(') : value;
            },

            formatNumber: function (value) {
                return new Intl.NumberFormat('zh-CN').format(Number(value) || 0);
            },

            safePageHref: function (value) {
                if (!value) return '/';
                try {
                    var url = new URL(String(value), window.location.origin);
                    if (url.origin !== window.location.origin || (url.protocol !== 'http:' && url.protocol !== 'https:')) return '#';
                    return url.href;
                } catch (error) {
                    return '#';
                }
            },

            displayPath: function (value) {
                if (!value) return '/';
                try {
                    var url = new URL(String(value), window.location.origin);
                    var result = url.pathname + url.search;
                    return result.length > 38 ? result.slice(0, 36) + '…' : result;
                } catch (error) {
                    return '/';
                }
            },

            deviceIcon: function (userAgent) {
                var ua = String(userAgent || '');
                if (/iPad|Tablet/i.test(ua)) return 'bi-tablet';
                if (/Mobile|Android|iPhone|Windows Phone/i.test(ua)) return 'bi-phone';
                return 'bi-laptop';
            },

            compactUserAgent: function (userAgent) {
                var ua = String(userAgent || '未知设备');
                var browser = /Edg\//.test(ua) ? 'Edge' : (/Chrome\//.test(ua) ? 'Chrome' : (/Firefox\//.test(ua) ? 'Firefox' : (/Safari\//.test(ua) ? 'Safari' : '浏览器')));
                var system = /iPhone|iPad/.test(ua) ? 'iOS' : (/Android/.test(ua) ? 'Android' : (/Mac OS X/.test(ua) ? 'macOS' : (/Windows/.test(ua) ? 'Windows' : '未知系统')));
                return browser + ' · ' + system;
            },

            locationLabel: function (location) {
                if (!location) return '未知位置';
                if (typeof location === 'string') return location || '未知位置';
                return location.location || [location.country, location.region, location.city].filter(Boolean).join(' ') || '未知位置';
            }
        },

        mounted: function () {
            this.refreshAll(false);
            var interval = Math.max(Number(config.refreshInterval) || 30000, 15000);
            this.refreshTimer = window.setInterval(function () {
                if (document.visibilityState === 'visible') this.refreshAll(false);
            }.bind(this), interval);
            window.addEventListener('admin:theme-change', this.renderTrendChart);
        },

        beforeUnmount: function () {
            if (this.refreshTimer) window.clearInterval(this.refreshTimer);
            if (this.chart) this.chart.destroy();
            window.removeEventListener('admin:theme-change', this.renderTrendChart);
        }
    }).mount(mountPoint);
}(window.Vue, window.Chart));
