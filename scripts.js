/**
 * 教学排课系统 - 通用JavaScript文件
 * 包含常用功能、模态框处理、表单验证等
 */

// 全局配置
const AppConfig = {
    debug: false,
    apiUrl: './api.php',
    loadingClass: 'loading',
    modalClass: 'modal',
    alertTimeout: 5000
};

// 工具函数
const Utils = {
    // 防抖函数
    debounce: function(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    // 节流函数
    throttle: function(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    },

    // 格式化日期
    formatDate: function(date, format = 'YYYY-MM-DD') {
        const d = new Date(date);
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const hour = String(d.getHours()).padStart(2, '0');
        const minute = String(d.getMinutes()).padStart(2, '0');
        const second = String(d.getSeconds()).padStart(2, '0');

        return format
            .replace('YYYY', year)
            .replace('MM', month)
            .replace('DD', day)
            .replace('HH', hour)
            .replace('mm', minute)
            .replace('ss', second);
    },

    // 显示通知
    showNotification: function(message, type = 'info', duration = AppConfig.alertTimeout) {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <i class="fas fa-${this.getNotificationIcon(type)}"></i>
                <span>${message}</span>
                <button class="notification-close" onclick="this.parentNode.parentNode.remove()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        document.body.appendChild(notification);

        // 自动移除
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, duration);

        return notification;
    },

    // 获取通知图标
    getNotificationIcon: function(type) {
        const icons = {
            success: 'check-circle',
            error: 'exclamation-triangle',
            warning: 'exclamation-circle',
            info: 'info-circle'
        };
        return icons[type] || icons.info;
    },

    // 验证邮箱
    validateEmail: function(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    },

    // 验证手机号
    validatePhone: function(phone) {
        const re = /^1[3-9]\d{9}$/;
        return re.test(phone);
    },

    // 生成随机字符串
    generateRandomString: function(length = 8) {
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        let result = '';
        for (let i = 0; i < length; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    },

    // 深拷贝对象
    deepClone: function(obj) {
        if (obj === null || typeof obj !== 'object') return obj;
        if (obj instanceof Date) return new Date(obj.getTime());
        if (obj instanceof Array) return obj.map(item => this.deepClone(item));
        if (typeof obj === 'object') {
            const clonedObj = {};
            for (const key in obj) {
                if (obj.hasOwnProperty(key)) {
                    clonedObj[key] = this.deepClone(obj[key]);
                }
            }
            return clonedObj;
        }
    }
};

// 模态框管理器
const ModalManager = {
    openModals: [],

    // 打开模态框
    open: function(modalId, options = {}) {
        const modal = document.getElementById(modalId);
        if (!modal) {
            console.error(`Modal with id "${modalId}" not found`);
            return;
        }

        // 添加到打开列表
        this.openModals.push(modalId);

        // 显示模态框
        modal.style.display = 'block';
        modal.classList.add('modal-open');

        // 添加背景遮罩
        this.addOverlay();

        // 动画效果
        setTimeout(() => {
            modal.classList.add('modal-show');
        }, 10);

        // 自动聚焦第一个输入框
        const firstInput = modal.querySelector('input:not([type="hidden"]), textarea, select');
        if (firstInput) {
            firstInput.focus();
        }

        // 阻止页面滚动
        document.body.style.overflow = 'hidden';

        // 绑定ESC键关闭
        document.addEventListener('keydown', this.handleEscKey);

        return modal;
    },

    // 关闭模态框
    close: function(modalId = null) {
        const targetModal = modalId ? document.getElementById(modalId) : this.getCurrentModal();
        if (!targetModal) return;

        // 从打开列表中移除
        const index = this.openModals.indexOf(targetModal.id);
        if (index > -1) {
            this.openModals.splice(index, 1);
        }

        // 隐藏动画
        targetModal.classList.remove('modal-show');

        setTimeout(() => {
            targetModal.style.display = 'none';
            targetModal.classList.remove('modal-open');

            // 如果没有其他打开的模态框，移除遮罩和恢复滚动
            if (this.openModals.length === 0) {
                this.removeOverlay();
                document.body.style.overflow = '';
                document.removeEventListener('keydown', this.handleEscKey);
            }
        }, 300);
    },

    // 关闭所有模态框
    closeAll: function() {
        while (this.openModals.length > 0) {
            this.close(this.openModals[0]);
        }
    },

    // 获取当前打开的模态框
    getCurrentModal: function() {
        if (this.openModals.length === 0) return null;
        const currentModalId = this.openModals[this.openModals.length - 1];
        return document.getElementById(currentModalId);
    },

    // 添加背景遮罩
    addOverlay: function() {
        let overlay = document.getElementById('modal-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'modal-overlay';
            overlay.className = 'modal-overlay';
            overlay.addEventListener('click', () => this.close());
            document.body.appendChild(overlay);
        }
        overlay.style.display = 'block';
    },

    // 移除背景遮罩
    removeOverlay: function() {
        const overlay = document.getElementById('modal-overlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    },

    // 处理ESC键事件
    handleEscKey: function(e) {
        if (e.key === 'Escape') {
            ModalManager.close();
        }
    }
};

// 表单验证器
const FormValidator = {
    // 验证规则
    rules: {
        required: function(value) {
            return value.trim() !== '';
        },
        email: function(value) {
            return Utils.validateEmail(value);
        },
        phone: function(value) {
            return Utils.validatePhone(value);
        },
        minLength: function(value, min) {
            return value.length >= min;
        },
        maxLength: function(value, max) {
            return value.length <= max;
        },
        number: function(value) {
            return !isNaN(value) && !isNaN(parseFloat(value));
        },
        min: function(value, min) {
            return parseFloat(value) >= min;
        },
        max: function(value, max) {
            return parseFloat(value) <= max;
        }
    },

    // 验证表单
    validate: function(form) {
        const errors = [];
        const fields = form.querySelectorAll('[data-validate]');

        fields.forEach(field => {
            const rules = field.dataset.validate.split('|');
            const value = field.value;
            const label = field.dataset.label || field.placeholder || '字段';

            rules.forEach(rule => {
                const [ruleName, ruleValue] = rule.split(':');
                
                if (this.rules[ruleName]) {
                    const isValid = ruleValue ? 
                        this.rules[ruleName](value, ruleValue) : 
                        this.rules[ruleName](value);

                    if (!isValid) {
                        errors.push({
                            field: field,
                            rule: ruleName,
                            message: this.getErrorMessage(ruleName, label, ruleValue)
                        });
                    }
                }
            });
        });

        return {
            isValid: errors.length === 0,
            errors: errors
        };
    },

    // 获取错误消息
    getErrorMessage: function(rule, label, value) {
        const messages = {
            required: `${label}不能为空`,
            email: `请输入正确的邮箱格式`,
            phone: `请输入正确的手机号格式`,
            minLength: `${label}长度不能少于${value}位`,
            maxLength: `${label}长度不能超过${value}位`,
            number: `${label}必须是数字`,
            min: `${label}不能小于${value}`,
            max: `${label}不能大于${value}`
        };
        return messages[rule] || `${label}格式不正确`;
    },

    // 显示错误
    showErrors: function(errors) {
        // 清除之前的错误
        document.querySelectorAll('.field-error').forEach(el => el.remove());
        document.querySelectorAll('.form-control.error').forEach(el => el.classList.remove('error'));

        errors.forEach(error => {
            const field = error.field;
            field.classList.add('error');

            const errorElement = document.createElement('div');
            errorElement.className = 'field-error';
            errorElement.textContent = error.message;
            
            if (field.parentNode) {
                field.parentNode.appendChild(errorElement);
            }
        });
    }
};

// AJAX请求管理器
const AjaxManager = {
    // 发送GET请求
    get: function(url, options = {}) {
        return this.request(url, { ...options, method: 'GET' });
    },

    // 发送POST请求
    post: function(url, data, options = {}) {
        return this.request(url, { 
            ...options, 
            method: 'POST', 
            body: JSON.stringify(data),
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            }
        });
    },

    // 发送表单数据
    postForm: function(url, formData, options = {}) {
        return this.request(url, { 
            ...options, 
            method: 'POST', 
            body: formData 
        });
    },

    // 通用请求方法
    request: function(url, options = {}) {
        const defaultOptions = {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        };

        const finalOptions = { ...defaultOptions, ...options };

        return fetch(url, finalOptions)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .catch(error => {
                console.error('Request failed:', error);
                throw error;
            });
    }
};

// 页面加载完成后的初始化
document.addEventListener('DOMContentLoaded', function() {
    // 初始化所有模态框的关闭按钮
    document.querySelectorAll('.modal .close-btn, .modal .btn-cancel').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const modal = this.closest('.modal');
            if (modal) {
                ModalManager.close(modal.id);
            }
        });
    });

    // 初始化表单验证
    document.querySelectorAll('form[data-validate]').forEach(form => {
        form.addEventListener('submit', function(e) {
            const validation = FormValidator.validate(this);
            if (!validation.isValid) {
                e.preventDefault();
                FormValidator.showErrors(validation.errors);
                Utils.showNotification('请检查表单中的错误', 'error');
            }
        });
    });

    // 初始化确认对话框
    document.querySelectorAll('[data-confirm]').forEach(element => {
        element.addEventListener('click', function(e) {
            const message = this.dataset.confirm;
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    });

    // 初始化自动隐藏的提示消息
    document.querySelectorAll('.alert[data-auto-hide]').forEach(alert => {
        const delay = parseInt(alert.dataset.autoHide) || AppConfig.alertTimeout;
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, delay);
    });

    // 初始化数据表格排序
    initTableSorting();

    // 初始化搜索框防抖
    initSearchDebounce();

    // 初始化工具提示
    initTooltips();
});

// 初始化表格排序
function initTableSorting() {
    document.querySelectorAll('table[data-sortable] th[data-sort]').forEach(th => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', function() {
            const table = this.closest('table');
            const column = this.dataset.sort;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            // 获取当前排序方向
            const currentDirection = this.dataset.direction || 'asc';
            const newDirection = currentDirection === 'asc' ? 'desc' : 'asc';

            // 清除所有排序标记
            table.querySelectorAll('th').forEach(header => {
                header.removeAttribute('data-direction');
                header.classList.remove('sort-asc', 'sort-desc');
            });

            // 设置新的排序标记
            this.dataset.direction = newDirection;
            this.classList.add(`sort-${newDirection}`);

            // 排序行
            rows.sort((a, b) => {
                const aValue = a.querySelector(`td:nth-child(${this.cellIndex + 1})`).textContent.trim();
                const bValue = b.querySelector(`td:nth-child(${this.cellIndex + 1})`).textContent.trim();

                if (newDirection === 'asc') {
                    return aValue.localeCompare(bValue, 'zh-CN', { numeric: true });
                } else {
                    return bValue.localeCompare(aValue, 'zh-CN', { numeric: true });
                }
            });

            // 重新插入排序后的行
            rows.forEach(row => tbody.appendChild(row));
        });
    });
}

// 初始化搜索防抖
function initSearchDebounce() {
    document.querySelectorAll('input[data-search]').forEach(input => {
        const searchHandler = Utils.debounce(function() {
            const searchUrl = input.dataset.search;
            const query = input.value.trim();
            
            if (searchUrl) {
                window.location.href = `${searchUrl}${searchUrl.includes('?') ? '&' : '?'}search=${encodeURIComponent(query)}`;
            }
        }, 500);

        input.addEventListener('input', searchHandler);
    });
}

// 初始化工具提示
function initTooltips() {
    document.querySelectorAll('[data-tooltip]').forEach(element => {
        element.addEventListener('mouseenter', function() {
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip-popup';
            tooltip.textContent = this.dataset.tooltip;
            document.body.appendChild(tooltip);

            const rect = this.getBoundingClientRect();
            tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
            tooltip.style.top = rect.top - tooltip.offsetHeight - 10 + 'px';

            this._tooltip = tooltip;
        });

        element.addEventListener('mouseleave', function() {
            if (this._tooltip) {
                this._tooltip.remove();
                delete this._tooltip;
            }
        });
    });
}

// 导出到全局作用域
window.Utils = Utils;
window.ModalManager = ModalManager;
window.FormValidator = FormValidator;
window.AjaxManager = AjaxManager;