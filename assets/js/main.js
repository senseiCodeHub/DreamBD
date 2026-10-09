// ===== ENHANCED MAIN.JS - DREAMBD APPLICATION =====

class DreamBDApp {
    constructor() {
        this.isInitialized = false;
        this.currentPage = null;
        this.isMobileMenuOpen = false;
        this.components = {};
        // Don't call init() in constructor - wait for DOM ready
    }

    async init() {
        if (this.isInitialized) {
            await this.refreshPage();
            return;
        }
        
        
        
        // Get current page from URL
        const urlParams = new URLSearchParams(window.location.search);
        this.currentPage = urlParams.get('page') || 'home';
        
        // Wait for core systems to be ready
        await this.waitForCoreSystems();
        
        
        
        // Initialize core components
        await this.initCoreComponents();
        
        // Initialize page-specific components
        await this.initPageComponents();
        
        // Mark as initialized
        this.isInitialized = true;
        
        
        
        // Dispatch app ready event
        this.dispatchEvent('appReady', { 
            page: this.currentPage,
            timestamp: Date.now() 
        });
        
        // Setup performance monitoring
        this.setupPerformanceMonitoring();
    }

    // Wait for core systems to be ready
    waitForCoreSystems() {
        return Promise.resolve();
    }

    async initCoreComponents() {
        try {
            // Initialize in sequence for better performance
            await Promise.all([
                this.initTheme(),
                this.initBackToTop(),
                this.initFormValidation(),
                this.initCart(),
                this.initAnimations(),
                this.initEventListeners()
            ]);
        } catch (error) {}
    }

    async initPageComponents() {
        // Page-specific initialization
        const pageScripts = {
            'home': this.initHomePage.bind(this),
            'profile': this.initProfilePage.bind(this),
            'products': this.initProductsPage.bind(this),
            'tournaments': this.initTournamentsPage.bind(this),
            'tournament': this.initGPCountdowns.bind(this),
            'cart': this.initCartPage.bind(this)
        };
        
        const initScript = pageScripts[this.currentPage];
        if (initScript && typeof initScript === 'function') {
            try {
                await initScript();
            } catch (error) {}
        }
    }

    async refreshPage(pageName = null) {
        const urlParams = new URLSearchParams(window.location.search);
        this.currentPage = pageName || this.currentPage || urlParams.get('page') || 'home';

        await this.initPageComponents();
        this.initFormValidation();
        this.initAnimations();

        if (typeof window.initCommunity === 'function') {
            window.initCommunity();
        }
        if (window.AuthHandler && typeof window.AuthHandler.initPageEnhancements === 'function') {
            window.AuthHandler.initPageEnhancements();
        }
        if (window.DreamBDNavbar && typeof window.DreamBDNavbar.init === 'function') {
            window.DreamBDNavbar.init();
        }
    }

    // Theme Management with persistence
    initTheme() {
        const themeToggle = document.getElementById('themeToggle');
        if (!themeToggle) return;
        
        const themeIcons = themeToggle.querySelectorAll('i');
        const prefersDarkScheme = window.matchMedia('(prefers-color-scheme: dark)');
        
        // Get saved theme or use system preference
        const savedTheme = localStorage.getItem('dreambd-theme');
        const currentTheme = savedTheme || 'light';
        
        // Apply theme
        this.applyTheme(currentTheme, themeIcons);
        
        // Theme toggle click handler
        themeToggle.addEventListener('click', () => {
            const current = localStorage.getItem('dreambd-theme') || currentTheme;
            const resolvedCurrent = this.resolveTheme(current, prefersDarkScheme);
            const newTheme = resolvedCurrent === 'dark' ? 'light' : 'dark';
            
            this.applyTheme(newTheme, themeIcons, prefersDarkScheme);
            localStorage.setItem('dreambd-theme', newTheme);
            
            this.dispatchEvent('themeChange', { theme: newTheme });
        });
        
        // Listen for system theme changes
        prefersDarkScheme.addEventListener('change', (e) => {
            const storedTheme = localStorage.getItem('dreambd-theme');
            if (!storedTheme || storedTheme === 'auto') {
                const newTheme = e.matches ? 'dark' : 'light';
                this.applyTheme(storedTheme || newTheme, themeIcons, prefersDarkScheme);
            }
        });

        document.addEventListener('themeChange', (event) => {
            const nextTheme = event.detail?.theme || localStorage.getItem('dreambd-theme') || 'light';
            this.applyTheme(nextTheme, themeIcons, prefersDarkScheme);
        });
        
        this.components.theme = { current: currentTheme };
    }
    
    resolveTheme(theme, prefersDarkScheme = window.matchMedia('(prefers-color-scheme: dark)')) {
        if (theme === 'auto') {
            return prefersDarkScheme.matches ? 'dark' : 'light';
        }
        return theme === 'dark' ? 'dark' : 'light';
    }

    applyTheme(theme, icons, prefersDarkScheme = window.matchMedia('(prefers-color-scheme: dark)')) {
        const resolvedTheme = this.resolveTheme(theme, prefersDarkScheme);
        
        // Use Tailwind's dark class strategy
        if (resolvedTheme === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        
        // Keep data-theme for backward compatibility
        document.documentElement.setAttribute('data-theme', resolvedTheme);
        
        if (icons?.length) {
            const moonIcon = icons[0];
            const sunIcon = icons[1];
            
            if (resolvedTheme === 'dark') {
                moonIcon.style.display = 'none';
                if (sunIcon) sunIcon.style.display = 'block';
            } else {
                moonIcon.style.display = 'block';
                if (sunIcon) sunIcon.style.display = 'none';
            }
        }
    }

    // Enhanced Mobile Menu with animations
    initMobileMenu() {
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const closeMenu = document.getElementById('closeMenu');
        const mobileMenu = document.getElementById('mobileMenu');
        const overlay = document.getElementById('mobileOverlay');
        
        if (!mobileMenuToggle || !mobileMenu) return;
        
        const openMobileMenu = () => {
            this.isMobileMenuOpen = true;
            mobileMenu.classList.add('active');
            if (overlay) overlay.classList.add('active');
            mobileMenuToggle.setAttribute('aria-expanded', 'true');
            mobileMenu.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            document.dispatchEvent(new CustomEvent('mobileMenuOpen'));
            
            // Focus first focusable element
            setTimeout(() => {
                const firstFocusable = mobileMenu.querySelector(
                    'a, button, input, [tabindex]:not([tabindex="-1"])'
                );
                if (firstFocusable) firstFocusable.focus();
            }, 100);
        };

        const closeMobileMenu = () => {
            this.isMobileMenuOpen = false;
            mobileMenu.classList.remove('active');
            if (overlay) overlay.classList.remove('active');
            mobileMenuToggle.setAttribute('aria-expanded', 'false');
            mobileMenu.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
            document.dispatchEvent(new CustomEvent('mobileMenuClose'));
            
            // Return focus to menu toggle
            mobileMenuToggle.focus();
        };

        // Event Listeners
        mobileMenuToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            if (this.isMobileMenuOpen) {
                closeMobileMenu();
            } else {
                openMobileMenu();
            }
        });

        if (closeMenu) {
            closeMenu.addEventListener('click', closeMobileMenu);
        }

        if (overlay) {
            overlay.addEventListener('click', closeMobileMenu);
        }

        // Close menu when clicking links
        const mobileLinks = mobileMenu.querySelectorAll('a');
        mobileLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (this.isMobileMenuOpen) {
                    closeMobileMenu();
                }
            });
        });

        // Close on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isMobileMenuOpen) {
                closeMobileMenu();
            }
        });

        // Trap focus in mobile menu
        mobileMenu.addEventListener('keydown', (e) => {
            if (e.key === 'Tab' && this.isMobileMenuOpen) {
                const focusableElements = mobileMenu.querySelectorAll(
                    'a, button, input, textarea, select, [tabindex]:not([tabindex="-1"])'
                );
                const firstElement = focusableElements[0];
                const lastElement = focusableElements[focusableElements.length - 1];

                if (e.shiftKey && document.activeElement === firstElement) {
                    e.preventDefault();
                    lastElement.focus();
                } else if (!e.shiftKey && document.activeElement === lastElement) {
                    e.preventDefault();
                    firstElement.focus();
                }
            }
        });

        this.components.mobileMenu = {
            open: openMobileMenu,
            close: closeMobileMenu,
            isOpen: () => this.isMobileMenuOpen
        };
    }

    // Enhanced Dropdowns with animations
    initDropdowns() {
        if (document.querySelector('[data-site-navigation]')) return;

        const dropdowns = document.querySelectorAll('.dropdown, .user-menu');
        
        dropdowns.forEach(dropdown => {
            const button = dropdown.querySelector('.dropdown-toggle, .user-btn');
            const menu = dropdown.querySelector('.dropdown-menu, .dropdown-content');
            
            if (!button || !menu) return;
            
            let isOpen = false;
            
            const toggleDropdown = (e) => {
                e.stopPropagation();
                isOpen = !isOpen;
                
                button.setAttribute('aria-expanded', isOpen.toString());
                
                if (isOpen) {
                    menu.style.display = 'block';
                    setTimeout(() => {
                        menu.style.opacity = '1';
                        menu.style.visibility = 'visible';
                        menu.style.transform = 'translateY(0) scale(1)';
                    }, 10);
                    
                    // Focus first item
                    setTimeout(() => {
                        const firstItem = menu.querySelector('a, button');
                        if (firstItem) firstItem.focus();
                    }, 50);
                } else {
                    menu.style.opacity = '0';
                    menu.style.visibility = 'hidden';
                    menu.style.transform = 'translateY(-10px) scale(0.95)';
                    setTimeout(() => {
                        menu.style.display = 'none';
                    }, 300);
                }
            };
            
            button.addEventListener('click', toggleDropdown);
            
            // Close dropdown when clicking outside
            document.addEventListener('click', (e) => {
                if (!dropdown.contains(e.target) && isOpen) {
                    isOpen = false;
                    button.setAttribute('aria-expanded', 'false');
                    menu.style.opacity = '0';
                    menu.style.visibility = 'hidden';
                    menu.style.transform = 'translateY(-10px) scale(0.95)';
                    setTimeout(() => {
                        menu.style.display = 'none';
                    }, 300);
                }
            });
            
            // Handle dropdown link clicks
            const dropdownLinks = menu.querySelectorAll('a[data-page], a[href]');
            dropdownLinks.forEach(link => {
                link.addEventListener('click', (e) => {
                    // Close dropdown when link is clicked
                    if (isOpen) {
                        isOpen = false;
                        button.setAttribute('aria-expanded', 'false');
                        menu.style.opacity = '0';
                        menu.style.visibility = 'hidden';
                        menu.style.transform = 'translateY(-10px) scale(0.95)';
                        setTimeout(() => {
                            menu.style.display = 'none';
                        }, 300);
                    }
                    
                    // If it's a data-page link, let the AJAX navigation handle it
                    if (link.hasAttribute('data-page')) {
                        // The event will bubble up to the document click handler
                        return;
                    }
                    
                    // For non-AJAX links (like logout), let them proceed normally
                    // Don't prevent default for logout or external links
                    if (link.getAttribute('href') === '#' || 
                        link.hasAttribute('data-no-ajax')) {
                        return;
                    }
                    
                    const href = link.getAttribute('href');
                    if (href && href.includes('index.php?page=')) {
                        e.preventDefault();
                        e.stopPropagation();
                        const page = new URL(href, window.location.origin).searchParams.get('page');
                        if (page && window.AjaxNavigation) {
                            window.AjaxNavigation.navigate(page, href);
                        }
                    }
                });
            });
            
            // Close on escape
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && isOpen) {
                    toggleDropdown({ stopPropagation: () => {} });
                }
            });
            
            // Keyboard navigation within dropdown
            menu.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    const items = menu.querySelectorAll('a, button');
                    const currentIndex = Array.from(items).indexOf(document.activeElement);
                    
                    let nextIndex;
                    if (e.key === 'ArrowDown') {
                        nextIndex = (currentIndex + 1) % items.length;
                    } else {
                        nextIndex = (currentIndex - 1 + items.length) % items.length;
                    }
                    
                    items[nextIndex].focus();
                }
            });
        });
    }

    // Enhanced Back to Top with progress indicator (uses main scroll container)
    initBackToTop() {
        const backToTop = document.getElementById('backToTop');
        const scrollContainer = document.getElementById('mainContent');
        if (!backToTop || !scrollContainer) return;
        
        const updateVisibility = () => {
            const scrollY = scrollContainer.scrollTop;
            const containerHeight = scrollContainer.clientHeight;
            const scrollHeight = scrollContainer.scrollHeight;
            
            if (scrollY > 300) {
                backToTop.classList.add('visible');
                const progress = Math.min((scrollY / (scrollHeight - containerHeight)) * 100, 100);
                backToTop.style.setProperty('--progress', `${progress}%`);
            } else {
                backToTop.classList.remove('visible');
            }
        };
        
        backToTop.addEventListener('click', () => {
            scrollContainer.scrollTo({ top: 0, behavior: 'smooth' });
            backToTop.classList.add('clicked');
            setTimeout(() => backToTop.classList.remove('clicked'), 300);
        });
        
        scrollContainer.addEventListener('scroll', updateVisibility);
        updateVisibility();
        
        this.components.backToTop = { update: updateVisibility };
    }

    // Enhanced Form Validation with real-time feedback
    initFormValidation() {
        const forms = document.querySelectorAll('form[needs-validation]');
        
        forms.forEach(form => {
            // Mark required fields
            const requiredFields = form.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                const label = field.closest('label') || field.previousElementSibling;
                if (label && !label.querySelector('.required-indicator')) {
                    const indicator = document.createElement('span');
                    indicator.className = 'required-indicator';
                    indicator.textContent = ' *';
                    indicator.setAttribute('aria-hidden', 'true');
                    label.appendChild(indicator);
                }
            });
            
            // Submit handler
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                
                if (!this.validateForm(form)) {
                    e.stopPropagation();
                    return;
                }
                
                form.classList.add('was-validated');
                
                // Handle AJAX form submission
                if (form.hasAttribute('data-ajax-form')) {
                    await this.handleAjaxFormSubmit(form);
                }
            });
            
            // Real-time validation
            const inputs = form.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                // Blur validation
                input.addEventListener('blur', () => {
                    this.validateField(input);
                });
                
                // Input validation (clear errors as user types)
                input.addEventListener('input', () => {
                    if (input.classList.contains('is-invalid')) {
                        this.validateField(input);
                    }
                });
                
                // Live character counter for textareas
                if (input.tagName === 'TEXTAREA' && input.hasAttribute('maxlength')) {
                    this.initCharacterCounter(input);
                }
            });
        });
    }
    
    validateForm(form) {
        let isValid = true;
        const inputs = form.querySelectorAll('[required], input, textarea, select');
        
        inputs.forEach(input => {
            if (!this.validateField(input)) {
                isValid = false;
            }
        });
        
        return isValid;
    }
    
    validateField(field) {
        const value = field.value.trim();
        const parent = field.closest('.form-group') || field.parentElement;
        
        // Remove existing error messages
        const existingError = parent.querySelector('.invalid-feedback');
        if (existingError) {
            existingError.remove();
        }
        
        field.classList.remove('is-invalid', 'is-valid');
        
        // Required validation
        if (field.hasAttribute('required') && !value) {
            field.classList.add('is-invalid');
            this.showError(field, field.getAttribute('data-error-required') || 'This field is required.');
            return false;
        }
        
        // Email validation
        if (field.type === 'email' && value) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(value)) {
                field.classList.add('is-invalid');
                this.showError(field, field.getAttribute('data-error-email') || 'Please enter a valid email address.');
                return false;
            }
        }
        
        // Password strength (if password field)
        if (field.type === 'password' && value) {
            if (!this.validatePassword(value)) {
                field.classList.add('is-invalid');
                this.showError(field, field.getAttribute('data-error-password') || 
                    'Password must be at least 8 characters with uppercase, lowercase, and numbers.');
                return false;
            }
        }
        
        // Length validation
        if (field.hasAttribute('minlength')) {
            const minLength = parseInt(field.getAttribute('minlength'));
            if (value.length < minLength) {
                field.classList.add('is-invalid');
                this.showError(field, `Must be at least ${minLength} characters.`);
                return false;
            }
        }
        
        if (field.hasAttribute('maxlength')) {
            const maxLength = parseInt(field.getAttribute('maxlength'));
            if (value.length > maxLength) {
                field.classList.add('is-invalid');
                this.showError(field, `Must be no more than ${maxLength} characters.`);
                return false;
            }
        }
        
        // Pattern validation
        if (field.hasAttribute('pattern') && value) {
            const pattern = new RegExp(field.getAttribute('pattern'));
            if (!pattern.test(value)) {
                field.classList.add('is-invalid');
                this.showError(field, field.getAttribute('data-error-pattern') || 'Please match the requested format.');
                return false;
            }
        }
        
        // Custom validation
        if (field.hasAttribute('data-custom-validate')) {
            const validateFn = field.getAttribute('data-custom-validate');
            if (window[validateFn] && typeof window[validateFn] === 'function') {
                const result = window[validateFn](value, field);
                if (result !== true) {
                    field.classList.add('is-invalid');
                    this.showError(field, result || 'Invalid value.');
                    return false;
                }
            }
        }
        
        field.classList.add('is-valid');
        return true;
    }
    
    validatePassword(password) {
        // At least 8 characters, 1 uppercase, 1 lowercase, 1 number
        const regex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/;
        return regex.test(password);
    }
    
    showError(field, message) {
        const parent = field.closest('.form-group') || field.parentElement;
        const errorDiv = document.createElement('div');
        errorDiv.className = 'invalid-feedback';
        errorDiv.textContent = message;
        parent.appendChild(errorDiv);
        
        // Add ARIA attributes
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', field.id + '-error');
        errorDiv.id = field.id + '-error';
    }
    
    initCharacterCounter(textarea) {
        const maxLength = parseInt(textarea.getAttribute('maxlength'));
        const counter = document.createElement('div');
        counter.className = 'character-counter';
        counter.textContent = `0/${maxLength}`;
        
        textarea.parentNode.appendChild(counter);
        
        textarea.addEventListener('input', () => {
            const length = textarea.value.length;
            counter.textContent = `${length}/${maxLength}`;
            
            if (length > maxLength * 0.9) {
                counter.classList.add('warning');
            } else {
                counter.classList.remove('warning');
            }
            
            if (length > maxLength) {
                counter.classList.add('error');
            } else {
                counter.classList.remove('error');
            }
        });
    }
    
    async handleAjaxFormSubmit(form) {
        const submitButton = form.querySelector('[type="submit"]');
        
        try {
            const formData = new FormData(form);
            const response = await fetch(form.action, {
                method: form.method,
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Form-Submission': 'true'
                }
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Reset form if needed
                if (result.resetForm) {
                    form.reset();
                    form.classList.remove('was-validated');
                    
                    // Clear validation states
                    const inputs = form.querySelectorAll('.is-valid, .is-invalid');
                    inputs.forEach(input => {
                        input.classList.remove('is-valid', 'is-invalid');
                    });
                    
                    // Clear error messages
                    const errors = form.querySelectorAll('.invalid-feedback');
                    errors.forEach(error => error.remove());
                }
                
                // Handle redirect
                if (result.redirect) {
                    setTimeout(() => {
                        if (window.AjaxNavigation) {
                            window.AjaxNavigation.navigate(result.redirect);
                        } else {
                            window.location.href = result.redirect;
                        }
                    }, 1000);
                }
                
                // Trigger custom callback
                if (result.callback && window[result.callback]) {
                    window[result.callback](result.data);
                }
                
            } else {
                // Show field-specific errors
                if (result.errors) {
                    Object.keys(result.errors).forEach(fieldName => {
                        const field = form.querySelector(`[name="${fieldName}"]`);
                        if (field) {
                            this.showError(field, result.errors[fieldName]);
                            field.classList.add('is-invalid');
                        }
                    });
                }
            }
        } catch (error) {}
    }

    // Enhanced Cart Management
    initCart() {
        const cartIcon = document.querySelector('.cart-icon');
        const cartCount = document.querySelector('.cart-count');
        
        if (!cartIcon || !cartCount) return;
        
        // Load cart from localStorage
        this.loadCart();
        
        // Cart icon click handler
        cartIcon.addEventListener('click', (e) => {
            e.preventDefault();
            if (window.AjaxNavigation) {
                window.AjaxNavigation.navigate('cart');
            } else {
                window.location.href = 'index.php?page=cart';
            }
        });
        
        // Listen for cart updates
        document.addEventListener('cartUpdated', (e) => {
            this.updateCartUI(e.detail);
        });
        
        // Initialize cart badge
        this.updateCartBadge();
    }
    
    loadCart() {
        const cart = JSON.parse(localStorage.getItem('dreambd-cart')) || {
            items: [],
            total: 0,
            count: 0
        };
        
        this.components.cart = cart;
        return cart;
    }
    
    saveCart() {
        if (this.components.cart) {
            localStorage.setItem('dreambd-cart', JSON.stringify(this.components.cart));
        }
    }
    
    updateCartUI(cartData) {
        // Update cart in memory
        if (cartData) {
            this.components.cart = cartData;
            this.saveCart();
        }
        
        // Update cart badge
        this.updateCartBadge();
        
        // Dispatch update event for other components
        this.dispatchEvent('cartStateChanged', this.components.cart);
    }
    
    updateCartBadge() {
        const cartCount = document.querySelector('.cart-count');
        if (!cartCount) return;
        
        const count = this.components.cart?.count || 0;
        cartCount.textContent = count > 99 ? '99+' : count;
        cartCount.setAttribute('aria-label', `${count} items in cart`);
        
        // Animation for new items
        if (count > 0 && !cartCount.classList.contains('has-items')) {
            cartCount.classList.add('has-items');
            cartCount.classList.add('animate-pulse');
            setTimeout(() => {
                cartCount.classList.remove('animate-pulse');
            }, 1000);
        } else if (count === 0) {
            cartCount.classList.remove('has-items');
        }
    }
    
    addToCart(product) {
        const cart = this.loadCart();
        
        // Check if product already exists
        const existingIndex = cart.items.findIndex(item => item.id === product.id);
        
        if (existingIndex > -1) {
            // Update quantity
            cart.items[existingIndex].quantity += product.quantity || 1;
        } else {
            // Add new item
            cart.items.push({
                ...product,
                quantity: product.quantity || 1,
                addedAt: Date.now()
            });
        }
        
        // Update totals
        cart.count = cart.items.reduce((sum, item) => sum + item.quantity, 0);
        cart.total = cart.items.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        
        // Save and update UI
        this.updateCartUI(cart);
        
        return cart;
    }
    
    removeFromCart(productId) {
        const cart = this.loadCart();
        cart.items = cart.items.filter(item => item.id !== productId);
        
        // Update totals
        cart.count = cart.items.reduce((sum, item) => sum + item.quantity, 0);
        cart.total = cart.items.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        
        this.updateCartUI(cart);
        return cart;
    }

    // Enhanced Animations with Intersection Observer
    initAnimations() {
        // Initialize Intersection Observer for scroll animations
        if ('IntersectionObserver' in window) {
            const observerOptions = {
                root: null,
                rootMargin: '0px',
                threshold: 0.1
            };
            
            const animationObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animate-in');
                        
                        // Add delay based on data attribute
                        const delay = entry.target.dataset.animationDelay || 0;
                        setTimeout(() => {
                            entry.target.classList.add('animate-visible');
                        }, delay);
                        
                        animationObserver.unobserve(entry.target);
                    }
                });
            }, observerOptions);
            
            // Observe elements with animation classes
            document.querySelectorAll('.animate-on-scroll').forEach(el => {
                animationObserver.observe(el);
            });
            
            // Lazy loading for images
            const imageObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        const src = img.getAttribute('data-src');
                        if (src) {
                            img.src = src;
                            img.classList.add('loaded');
                            imageObserver.unobserve(img);
                        }
                    }
                });
            });
            
            document.querySelectorAll('img[data-src]').forEach(img => {
                imageObserver.observe(img);
            });
        }
        
        // Initialize hover animations
        this.initHoverAnimations();
    }
    
    initHoverAnimations() {
        // Add hover effects to interactive elements
        const hoverElements = document.querySelectorAll('.hover-lift, .hover-scale, .hover-glow');
        
        hoverElements.forEach(element => {
            element.addEventListener('mouseenter', () => {
                element.classList.add('hover-active');
            });
            
            element.addEventListener('mouseleave', () => {
                element.classList.remove('hover-active');
            });
        });
    }

    // Event Listeners
    initEventListeners() {
        // Handle external links
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a');
            if (link && link.href && link.hostname !== window.location.hostname) {
                if (!link.hasAttribute('target')) {
                    link.setAttribute('target', '_blank');
                }
                if (!link.hasAttribute('rel')) {
                    link.setAttribute('rel', 'noopener noreferrer');
                }
                
                // Track external link clicks
                if (window.gtag) {
                    gtag('event', 'external_link_click', {
                        link_url: link.href,
                        link_text: link.textContent
                    });
                }
            }
        });
        
        // Handle AJAX navigation integration
        document.addEventListener('pageChanged', (e) => {
            this.currentPage = e.detail.page;
            this.handlePageChange(e.detail);
        });
        
        // Handle app ready event
        document.addEventListener('appReady', () => {
            this.onAppReady();
        });
        
        // Handle window resize with debounce
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                this.handleResize();
            }, 250);
        });
        
        // Handle beforeunload for cleanup
        window.addEventListener('beforeunload', () => {
            this.cleanup();
        });
        
        // Handle online/offline events
        window.addEventListener('online', () => {
            this.handleOnlineStatus(true);
        });
        
        window.addEventListener('offline', () => {
            this.handleOnlineStatus(false);
        });
    }
    
    handlePageChange(pageData) {
        
        
        // Close mobile menu if open
        if (this.isMobileMenuOpen && this.components.mobileMenu?.close) {
            this.components.mobileMenu.close();
        }
        
        // Close any open dropdowns
        document.querySelectorAll('.dropdown-menu').forEach(menu => {
            menu.style.display = 'none';
            menu.style.opacity = '0';
            menu.style.visibility = 'hidden';
        });
        
        // Update current page
        this.currentPage = pageData.page;
        
        // Reinitialize page-specific components and injected-page enhancements
        this.refreshPage(pageData.page);
        
        // Dispatch custom event
        this.dispatchEvent('pageContentLoaded', pageData);
    }
    
    onAppReady() {
        
        
        // Add loaded class to body for CSS transitions
        document.body.classList.add('app-loaded');
        
        // Initialize any deferred components
        this.initDeferredComponents();
        
        // Track app load in analytics
        if (window.gtag) {
            gtag('event', 'app_ready', {
                page: this.currentPage,
                load_time: performance.now()
            });
        }
    }
    
    handleResize() {
        // Handle responsive behaviors
        const isMobile = window.innerWidth < 768;
        
        // Update mobile menu state
        if (!isMobile && this.isMobileMenuOpen) {
            this.components.mobileMenu?.close();
        }
        
        // Dispatch resize event
        this.dispatchEvent('windowResized', {
            width: window.innerWidth,
            height: window.innerHeight,
            isMobile: isMobile
        });
    }
    
    cleanup() {
        // Cleanup before page unload
        if (this.components.cart) {
            this.saveCart();
        }
        
        // Clear any intervals or timeouts
        // (Add your cleanup logic here)
    }
    
    handleOnlineStatus(isOnline) {
        if (isOnline) {
            
        } else {
            
        }
        
        this.dispatchEvent('networkStatusChange', { online: isOnline });
    }
    
    initDeferredComponents() {
        // Initialize components that aren't critical for initial load
        // This can include:
        // - Lazy-loaded images
        // - Third-party widgets
        // - Analytics scripts
        // - Social media integrations
    }

    // Utility Methods
    dispatchEvent(name, detail) {
        const event = new CustomEvent(name, {
            detail: detail,
            bubbles: true,
            cancelable: true
        });
        
        document.dispatchEvent(event);
    }
    
    setupPerformanceMonitoring() {
        // Monitor performance metrics
        if (window.performance && performance.getEntriesByType) {
            // Log initial load performance
            setTimeout(() => {
                const paintMetrics = performance.getEntriesByType('paint');
                const navTiming = performance.timing;
                
                if (paintMetrics.length > 0) {
                    
                }
            }, 1000);
        }
        
        // Monitor memory usage (if supported)
        if (performance.memory) {
            setInterval(() => {
                const memory = performance.memory;
                if (memory.usedJSHeapSize > 500000000) { // 500MB
                    
                }
            }, 60000); // Check every minute
        }
    }

    // Page-specific initializers
    initHomePage() {
        // Home page specific initialization
        
        
        // Initialize hero slider if exists
        const heroSlider = document.querySelector('.hero-slider');
        if (heroSlider) {
            this.initHeroSlider(heroSlider);
        }
        
        // Initialize featured products
        this.initFeaturedProducts();
    }
    
    initProfilePage() {
        // Profile page specific initialization
        
        
        // Initialize profile tabs
        this.initProfileTabs();
    }
    
    initProductsPage() {
        // Products page specific initialization
        
        
        // Initialize product filters
        this.initProductFilters();
        
        // Initialize product grid
        this.initProductGrid();
    }
    
    initTournamentsPage() {
        
        const inits = [
            'initGPParticles', 'initGPCards', 'initGPCountdowns', 'initGPStatCount', 'initGPTabs',
            'initGPSearch', 'initGPModals', 'initGPForms', 'initGPUnregister',
            'initGPViewParticipants', 'initGPTeamManage', 'initGPTeamDelete', 'initGPHistory',
            'initGPIcons', 'initGPColors', 'initGPScroll'
        ];
        inits.forEach(name => {
            try {
                this[name]();
            } catch (e) {}
        });
    }

    initGPCards() {
        // Card entrance animation - stagger fade-in
        const cards = document.querySelectorAll('.gp-card');
        cards.forEach((c, i) => {
            c.style.opacity = '0';
            c.style.transform = 'translateY(20px)';
            c.style.transition = 'opacity .5s ease, transform .5s cubic-bezier(.22,1,.36,1)';
            setTimeout(() => {
                c.style.opacity = '1';
                c.style.transform = 'translateY(0)';
            }, 80 + i * 60);
        });
    }

    initGPStatCount() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        document.querySelectorAll('.gp-hero-stats .gp-stat-value').forEach(el => {
            if (el.dataset.counted) return;
            el.dataset.counted = '1';
            const raw = el.textContent.trim();
            const prefix = (/^\D*/.exec(raw) || [''])[0];
            const target = parseFloat(raw.replace(/[^\d.]/g, '')) || 0;
            if (target <= 0) return;
            el.textContent = prefix + '0';
            const dur = 950;
            const t0 = performance.now();
            const step = (t) => {
                const p = Math.min(1, (t - t0) / dur);
                const e = 1 - Math.pow(1 - p, 3);
                el.textContent = prefix + Math.round(target * e).toLocaleString('en-US');
                if (p < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        });
    }

    initGPParticles() {
        const canvas = document.getElementById('gpParticles');
        if (!canvas) return;

        const ctx = canvas.getContext('2d');
        let w = canvas.width = window.innerWidth;
        let h = canvas.height = window.innerHeight;
        const particles = [];
        const count = Math.min(Math.floor(w * h / 8000), 80);
        const colors = ['124,58,237', '37,99,235', '236,72,153', '16,185,129', '245,158,11'];

        for (let i = 0; i < count; i++) {
            particles.push({
                x: Math.random() * w,
                y: Math.random() * h,
                vx: (Math.random() - 0.5) * 0.6,
                vy: (Math.random() - 0.5) * 0.6,
                r: Math.random() * 2.5 + 1,
                c: colors[Math.floor(Math.random() * colors.length)],
                a: Math.random() * 0.5 + 0.2,
            });
        }

        const animate = () => {
            ctx.clearRect(0, 0, w, h);
            const isDark = document.documentElement.classList.contains('dark');

            particles.forEach((p, i) => {
                p.x += p.vx;
                p.y += p.vy;
                if (p.x < 0 || p.x > w) p.vx *= -1;
                if (p.y < 0 || p.y > h) p.vy *= -1;

                ctx.beginPath();
                ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(${p.c},${isDark ? p.a + 0.2 : p.a})`;
                ctx.fill();

                // Lines
                for (let j = i + 1; j < particles.length; j++) {
                    const dx = p.x - particles[j].x;
                    const dy = p.y - particles[j].y;
                    const dist = Math.sqrt(dx * dx + dy * dy);
                    if (dist < 120) {
                        ctx.beginPath();
                        ctx.moveTo(p.x, p.y);
                        ctx.lineTo(particles[j].x, particles[j].y);
                        ctx.strokeStyle = `rgba(${p.c},${(1 - dist / 120) * (isDark ? 0.2 : 0.12)})`;
                        ctx.lineWidth = 0.6;
                        ctx.stroke();
                    }
                }
            });

            requestAnimationFrame(animate);
        };

        animate();

        window.addEventListener('resize', () => {
            w = canvas.width = window.innerWidth;
            h = canvas.height = window.innerHeight;
        });
    }

    initGPCountdowns() {
        const els = document.querySelectorAll('.gp-countdown');
        if (!els.length) return;

        const tick = (el) => {
            const ts = parseInt(el.getAttribute('data-starts'), 10);
            if (!ts) return;
            const diff = ts - Math.floor(Date.now() / 1000);
            if (diff <= 0) { el.textContent = 'LIVE'; el.style.color = '#16a34a'; return; }
            const d = Math.floor(diff / 86400), h = Math.floor((diff % 86400) / 3600), m = Math.floor((diff % 3600) / 60), s = diff % 60;
            el.textContent = d > 0 ? `${d}d ${h}h` : h > 0 ? `${h}h ${m}m ${s}s` : `${m}m ${s}s`;
        };
        els.forEach(tick);
        clearInterval(this._gpInterval);
        this._gpInterval = setInterval(() => document.querySelectorAll('.gp-countdown').forEach(tick), 1000);
    }

    initGPTabs() {
        document.querySelectorAll('.gp-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                tab.closest('.gp-tabs')?.querySelectorAll('.gp-tab').forEach(t => { t.classList.remove('active'); t.setAttribute('aria-selected','false'); });
                tab.classList.add('active'); tab.setAttribute('aria-selected','true');
                const f = tab.getAttribute('data-filter');
                document.querySelectorAll('.gp-card').forEach(c => c.classList.toggle('hidden', f !== 'all' && c.getAttribute('data-status') !== f));
            });
        });
    }

    initGPSearch() {
        const input = document.getElementById('gpSearch');
        if (!input) return;
        input.addEventListener('input', () => {
            const q = input.value.trim().toLowerCase();
            document.querySelectorAll('.gp-card').forEach(c => {
                const t = c.querySelector('h3')?.textContent?.toLowerCase() || '';
                c.classList.toggle('hidden', q && !t.includes(q));
            });
        });
    }

    initGPModals() {
        const overlay = document.getElementById('gpOverlay');
        if (!overlay) return;

        const open = (id) => {
            const m = document.getElementById(id);
            if (!m) return;
            overlay.classList.remove('hidden');
            m.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        };

        const closeAll = () => {
            document.querySelectorAll('.gp-modal').forEach(m => m.classList.add('hidden'));
            overlay.classList.add('hidden');
            document.body.style.overflow = '';
        };

        document.querySelectorAll('[data-open-modal]').forEach(btn => btn.addEventListener('click', () => open(btn.getAttribute('data-open-modal'))));
        document.querySelectorAll('[data-close-modal]').forEach(btn => btn.addEventListener('click', closeAll));
        overlay.addEventListener('click', closeAll);
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });

        this._closeModals = closeAll;
    }

    escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    async _api(data) {
        const res = await fetch('handlers/tournament_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(data)
        });
        return res.json();
    }

    _showFeedback(form, result) {
        const fb = form.querySelector('.gp-feedback');
        if (!fb) return;
        fb.classList.remove('hidden', 'success', 'error');
        fb.textContent = result.message || 'Done.';
        fb.classList.add(result.success ? 'success' : 'error');
    }

    _showBaResult(success, title, sub) {
        const overlay = document.getElementById('baResultOverlay');
        const icon = document.getElementById('baResultIcon');
        const titleEl = document.getElementById('baResultTitle');
        const subEl = document.getElementById('baResultSub');
        const doneBtn = document.getElementById('baResultDoneBtn');
        const closeBtn = document.getElementById('baResultClose');
        if (!overlay) return;
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        if (icon) {
            icon.className = 'gp-success-icon-wrap ' + (success ? 'success' : 'fail');
            icon.innerHTML = success ? '<i class="fas fa-check"></i>' : '<i class="fas fa-times"></i>';
        }
        if (titleEl) titleEl.textContent = title.replace(/[✅❌]/g,'').trim();
        if (subEl) subEl.textContent = sub;
        if (doneBtn) {
            if (success) {
                doneBtn.innerHTML = '<i class="fas fa-check-circle"></i> ঠিক আছে';
                doneBtn.className = 'gp-success-btn primary';
                doneBtn.onclick = () => { overlay.style.display = 'none'; document.body.style.overflow = ''; location.reload(); };
            } else {
                doneBtn.innerHTML = '<i class="fas fa-undo"></i> আবার চেষ্টা করুন';
                doneBtn.className = 'gp-success-btn secondary';
                doneBtn.onclick = () => { overlay.style.display = 'none'; document.body.style.overflow = ''; };
            }
        }
        if (closeBtn) {
            closeBtn.onclick = () => { overlay.style.display = 'none'; document.body.style.overflow = ''; if (success) location.reload(); };
        }
    }

    _toast(msg, type = 'success') {
        const old = document.querySelector('.gp-toast');
        if (old) old.remove();
        const t = document.createElement('div');
        t.className = `gp-toast ${type}`;
        t.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i><span>${this.escHtml(msg)}</span>`;
        document.body.appendChild(t);
        setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateY(20px)'; t.style.transition = 'all .3s'; setTimeout(() => t.remove(), 300); }, 3000);
    }

    initGPForms() {
        const csrf = document.getElementById('tournamentsPage')?.getAttribute('data-csrf') || '';
        const pageEl = document.getElementById('tournamentsPage');

        // ─── Become Agent via bKash ───
        const baStep1Form = document.getElementById('baStep1Form');
        if (baStep1Form) {
            baStep1Form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = baStep1Form.querySelector('button[type="submit"]');
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending OTP...';
                const data = Object.fromEntries(new FormData(baStep1Form));
                data.csrf_token = csrf;
                const result = await this._api(data);
                this._showFeedback(baStep1Form, result);
                if (result.success) {
                    document.getElementById('baStep1').style.display = 'none';
                    document.getElementById('baStep2').style.display = 'block';
                    document.getElementById('baPhoneDisplay').textContent = data.bkash_phone || '';
                    document.getElementById('baDemoOtp').textContent = result.demo_otp || '------';
                    const first = document.querySelector('#baOtpInputs .gp-otp-box');
                    if (first) setTimeout(() => first.focus(), 100);
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Pay $5.00 via bKash';
                }
            });
        }

        // BA OTP inputs
        const baOtpInputs = document.querySelectorAll('#baOtpInputs .gp-otp-box');
        baOtpInputs.forEach((input, idx) => {
            input.addEventListener('input', () => {
                if (input.value && idx < baOtpInputs.length - 1) baOtpInputs[idx + 1].focus();
                document.getElementById('baOtpHidden').value = Array.from(baOtpInputs).map(i => i.value).join('');
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !input.value && idx > 0) baOtpInputs[idx - 1].focus();
                if (e.key === 'Enter') {
                    document.getElementById('baOtpHidden').value = Array.from(baOtpInputs).map(i => i.value).join('');
                    document.getElementById('baStep2Form')?.requestSubmit();
                }
            });
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const cd = e.clipboardData || window.clipboardData;
                if (!cd) return;
                const paste = cd.getData('text').replace(/\D/g, '').slice(0, 6);
                paste.split('').forEach((ch, i) => { if (baOtpInputs[i]) baOtpInputs[i].value = ch; });
                const next = baOtpInputs[Math.min(paste.length, baOtpInputs.length - 1)];
                if (next) next.focus();
                document.getElementById('baOtpHidden').value = Array.from(baOtpInputs).map(i => i.value).join('');
            });
        });

        // BA Step 2: Verify OTP
        const baStep2Form = document.getElementById('baStep2Form');
        if (baStep2Form) {
            baStep2Form.addEventListener('submit', async (e) => {
                e.preventDefault();
                document.getElementById('baOtpHidden').value = Array.from(baOtpInputs).map(i => i.value).join('');
                const btn = baStep2Form.querySelector('button[type="submit"]');
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Activating...';
                const data = Object.fromEntries(new FormData(baStep2Form));
                data.csrf_token = csrf;
                const result = await this._api(data);
                this._showFeedback(baStep2Form, result);
                if (result.success) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Activated!';
                    this._toast(result.message);
                    setTimeout(() => window.location.reload(), 1200);
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-crown"></i> Confirm & Activate';
                }
            });
        }

        // BA Back button
        const baBackBtn = document.getElementById('baBackBtn');
        if (baBackBtn) {
            baBackBtn.addEventListener('click', () => {
                document.getElementById('baStep2').style.display = 'none';
                document.getElementById('baStep1').style.display = 'block';
                baOtpInputs.forEach(i => i.value = '');
                document.getElementById('baOtpHidden').value = '';
                document.querySelectorAll('#baStep2Form .gp-feedback').forEach(el => { el.classList.add('hidden'); el.textContent = ''; });
            });
        }

        // ─── bKash Payment Flow ───

        // Step 1: Send OTP
        const bkashStep1Form = document.getElementById('bkashStep1Form');
        if (bkashStep1Form) {
            bkashStep1Form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = bkashStep1Form.querySelector('button[type="submit"]');
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending OTP...';
                const data = Object.fromEntries(new FormData(bkashStep1Form));
                data.csrf_token = csrf;
                data.amount = document.getElementById('bkashAmount')?.value || '0';
                const result = await this._api(data);
                this._showFeedback(bkashStep1Form, result);
                if (result.success) {
                    // Show step 2
                    document.getElementById('bkashStep1').style.display = 'none';
                    document.getElementById('bkashStep2').style.display = 'block';
                    document.getElementById('bkashPhoneDisplay').textContent = data.bkash_phone || '';
                    document.getElementById('bkashDemoOtp').textContent = result.demo_otp || '------';
                    // Focus first OTP input
                    const first = document.querySelector('#bkashOtpInputs .gp-otp-box');
                    if (first) setTimeout(() => first.focus(), 100);
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send OTP';
                }
            });
        }

        // OTP input auto-advance
        const otpInputs = document.querySelectorAll('#bkashOtpInputs .gp-otp-box');
        otpInputs.forEach((input, idx) => {
            input.addEventListener('input', () => {
                if (input.value && idx < otpInputs.length - 1) {
                    otpInputs[idx + 1].focus();
                }
                // Update hidden field
                document.getElementById('bkashOtpHidden').value = Array.from(otpInputs).map(i => i.value).join('');
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !input.value && idx > 0) {
                    otpInputs[idx - 1].focus();
                }
                if (e.key === 'Enter') {
                    document.getElementById('bkashOtpHidden').value = Array.from(otpInputs).map(i => i.value).join('');
                    document.getElementById('bkashStep2Form')?.requestSubmit();
                }
            });
            input.addEventListener('paste', (e) => {
                e.preventDefault();
                const cd = e.clipboardData || window.clipboardData;
                if (!cd) return;
                const paste = cd.getData('text').replace(/\D/g, '').slice(0, 6);
                paste.split('').forEach((ch, i) => {
                    if (otpInputs[i]) { otpInputs[i].value = ch; }
                });
                const next = otpInputs[Math.min(paste.length, otpInputs.length - 1)];
                if (next) next.focus();
                document.getElementById('bkashOtpHidden').value = Array.from(otpInputs).map(i => i.value).join('');
            });
        });

        // Step 2: Verify OTP
        const bkashStep2Form = document.getElementById('bkashStep2Form');
        if (bkashStep2Form) {
            bkashStep2Form.addEventListener('submit', async (e) => {
                e.preventDefault();
                document.getElementById('bkashOtpHidden').value = Array.from(otpInputs).map(i => i.value).join('');
                const btn = bkashStep2Form.querySelector('button[type="submit"]');
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
                const data = Object.fromEntries(new FormData(bkashStep2Form));
                data.csrf_token = csrf;
                const result = await this._api(data);
                this._showFeedback(bkashStep2Form, result);
                if (result.success) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Payment confirmed!';
                    this._toast(result.message);
                    setTimeout(() => window.location.reload(), 1200);
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Payment';
                }
            });
        }

        // bKash back button
        const bkashBackBtn = document.getElementById('bkashBackBtn');
        if (bkashBackBtn) {
            bkashBackBtn.addEventListener('click', () => {
                document.getElementById('bkashStep2').style.display = 'none';
                document.getElementById('bkashStep1').style.display = 'block';
                // Reset OTP inputs
                otpInputs.forEach(i => i.value = '');
                document.getElementById('bkashOtpHidden').value = '';
                // Clear feedback
                document.querySelectorAll('#bkashStep2Form .gp-feedback').forEach(el => { el.classList.add('hidden'); el.textContent = ''; });
            });
        }

        // bKash amount buttons
        document.querySelectorAll('#bKashModal .gp-amount-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('#bKashModal .gp-amount-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const input = document.getElementById('bkashAmount');
                if (input) input.value = btn.getAttribute('data-amount');
            });
        });

        // Create Tournament
        const ctForm = document.getElementById('createTournamentForm');
        if (ctForm) {
            ctForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = ctForm.querySelector('button[type="submit"]');
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
                const data = Object.fromEntries(new FormData(ctForm));
                data.csrf_token = csrf;
                const result = await this._api(data);
                this._showFeedback(ctForm, result);
                if (result.success) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Created!';
                    setTimeout(() => window.location.reload(), 1200);
                } else { btn.disabled = false; btn.innerHTML = '<i class="fas fa-trophy"></i> Create tournament'; }
            });
        }

        // Create Team
        const teamForm = document.getElementById('createTeamForm');
        if (teamForm) {
            teamForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = teamForm.querySelector('button[type="submit"]');
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
                const data = Object.fromEntries(new FormData(teamForm));
                data.csrf_token = csrf;
                const result = await this._api(data);
                this._showFeedback(teamForm, result);
                if (result.success) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Created!';
                    setTimeout(() => window.location.reload(), 1000);
                } else { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Create team'; }
            });
        }

        // Join Tournament
        const jtForm = document.getElementById('joinTournamentForm');
        if (jtForm) {
            // Radio toggle
            jtForm.querySelectorAll('input[name="join_type"]').forEach(r => {
                r.addEventListener('change', () => {
                    document.getElementById('teamSelectGroup').style.display = r.value === 'team' ? 'block' : 'none';
                    document.getElementById('soloNameGroup').style.display = r.value === 'solo' ? 'block' : 'none';
                });
            });

            jtForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = jtForm.querySelector('button[type="submit"]');
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Joining...';
                const fd = new FormData(jtForm);
                const data = Object.fromEntries(fd);
                data.csrf_token = csrf;

                // If team selected but wrong action type
                if (data.join_type === 'team' && data.team_id) {
                    data.action = 'register';
                } else {
                    data.action = 'register';
                    delete data.team_id;
                }

                const result = await this._api(data);
                this._showFeedback(jtForm, result);
                if (result.success) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Joined!';
                    setTimeout(() => window.location.reload(), 1000);
                } else { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Confirm join'; }
            });
        }

        // Join buttons
        document.querySelectorAll('.gp-join-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-id');
                const title = btn.getAttribute('data-title');
                const fee = parseFloat(btn.getAttribute('data-fee') || '0');

                document.getElementById('joinTournamentId').value = id;
                document.getElementById('joinTournamentTitle').textContent = title;

                const feeEl = document.getElementById('joinTournamentFee');
                if (fee > 0) {
                    feeEl.textContent = `Entry fee: $${fee.toFixed(2)} will be deducted from your balance.`;
                    feeEl.style.display = 'block';
                } else {
                    feeEl.style.display = 'none';
                }

                // Reset form
                const f = document.getElementById('joinTournamentForm');
                f?.querySelectorAll('.gp-feedback').forEach(el => { el.classList.add('hidden'); el.textContent = ''; });
                const solo = f?.querySelector('input[value="solo"]');
                if (solo) solo.checked = true;
                document.getElementById('teamSelectGroup').style.display = 'none';
                document.getElementById('soloNameGroup').style.display = 'block';

                const overlay = document.getElementById('gpOverlay');
                const modal = document.getElementById('joinTournamentModal');
                if (overlay) overlay.classList.remove('hidden');
                if (modal) modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            });
        });

        // Gamer Profile — click avatar/meta to open modal
        document.querySelectorAll('[data-gp-profile]').forEach(el => {
            el.addEventListener('click', () => {
                if (this._closeModals) this._closeModals();
                const pageEl = document.getElementById('tournamentsPage');
                const hasProfile = pageEl?.getAttribute('data-has-profile') === '1';
                const modalId = hasProfile ? 'viewGamerProfileModal' : 'createGamerProfileModal';
                const overlay = document.getElementById('gpOverlay');
                const modal = document.getElementById(modalId);
                if (overlay) overlay.classList.remove('hidden');
                if (modal) modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            });
        });

        // Edit Profile — switch from view to edit modal
        document.querySelectorAll('[data-edit-profile]').forEach(el => {
            el.addEventListener('click', () => {
                const viewModal = document.getElementById('viewGamerProfileModal');
                const editModal = document.getElementById('createGamerProfileModal');
                if (viewModal) viewModal.classList.add('hidden');
                if (editModal) editModal.classList.remove('hidden');
            });
        });

        // Gamer Profile Form — save via AJAX
        const gpForm = document.getElementById('gamerProfileForm');
        if (gpForm) {
            gpForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const btn = gpForm.querySelector('button[type="submit"]');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                const data = Object.fromEntries(new FormData(gpForm));
                data.csrf_token = csrf;
                data.action = 'update_profile';
                const result = await this._api(data);
                this._showFeedback(gpForm, result);
                if (result.success) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Saved!';
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i> Save Changes';
                }
            });
        }

        // Become Agent — step transition + payment flow
        const baInfoStep = document.getElementById('baInfoStep');
        const baPayStep = document.getElementById('baPayStep');
        const baProceedBtn = document.getElementById('baProceedBtn');
        const baBackToInfo = document.getElementById('baBackToInfo');
        const baPayBtn = document.getElementById('baPayBtn');
        const baPhone = document.getElementById('baPhone');
        const baTxid = document.getElementById('baTxid');
        const baFeedback = document.getElementById('baFeedback');
        const baMerchantBox = document.getElementById('baMerchantBox');

        let baCurrentMethod = 'bkash';

        const _baPmFallback = {
            bkash: { number: '01888780877', instruction: 'send_money' },
            nagad: { number: '01888780877', instruction: 'send_money' },
            rocket: { number: '01888780877', instruction: 'send_money' }
        };
        const _baPmData = () => (window.baPmData && Object.keys(window.baPmData).length ? window.baPmData : _baPmFallback);

        const baUpdateMerchant = () => {
            const d = _baPmData()[baCurrentMethod];
            if (!d) { if (baMerchantBox) baMerchantBox.style.display = 'none'; return; }
            const label = d.instruction === 'cashout' ? 'ক্যাশ আউট' : 'সেন্ড মানি';
            const colors = {bkash:'#E2136E', nagad:'#E8522E', rocket:'#CC0000'};
            const names = {bkash:'bKash', nagad:'Nagad', rocket:'Rocket'};
            const dials = {bkash:'*247#', nagad:'*167#', rocket:'*322#'};
            const c = colors[baCurrentMethod] || '#6b7280';
            const n = names[baCurrentMethod] || baCurrentMethod.toUpperCase();
            const mNum = d.number || '01888780877';
            if (!baMerchantBox) return;
            baMerchantBox.innerHTML =
                '<div class="ba-merchant-header"><img src="assets/images/payment-icon/'+baCurrentMethod+'-logo-mobile-banking.png" alt="" onerror="this.style.display=\'none\'"><span style="color:'+c+'">'+n+'</span></div>' +
                '<div class="ba-instr-step">১. <strong>'+dials[baCurrentMethod]+'</strong> ডায়াল করুন অথবা '+n+' অ্যাপ খুলুন।</div>' +
                '<div class="ba-instr-step">২. <strong>"'+label+'"</strong> অপশন সিলেক্ট করুন।</div>' +
                '<div class="ba-instr-step">৩. প্রাপক নম্বর লিখুন: <strong class="ba-merchant-num" style="color:'+c+'">'+mNum+'</strong> <button onclick="baCopyNumber()" class="ba-copy-btn"><i class="fas fa-copy"></i> কপি</button></div>' +
                '<div class="ba-instr-step">৪. টাকার পরিমাণ লিখুন: <strong>৳৫০০</strong></div>' +
                '<div class="ba-instr-step">৫. আপনার পিন দিন এবং কনফার্ম বাটনে ক্লিক করুন।</div>' +
                '<div class="ba-instr-step">৬. কনফার্মেশন মেসেজ থেকে <strong>Transaction ID</strong> কপি করে নিচে দিন।</div>' +
                '<div class="ba-instr-footer">এখন নিচের বক্সে TXID দিন এবং <strong style="color:#7c3aed">সাবমিট</strong> বাটনে ক্লিক করুন। ✅</div>';
            baMerchantBox.style.display = 'block';
        };

        window.baUpdateMerchant = baUpdateMerchant;

        // Copy number helper
        window.baCopyNumber = () => {
            const d = _baPmData()[baCurrentMethod];
            if (!d || !baMerchantBox) return;
            navigator.clipboard.writeText(d.number).then(() => {
                const btn = baMerchantBox.querySelector('.ba-copy-btn');
                if (btn) { const t = btn.innerHTML; btn.innerHTML = '<i class="fas fa-check"></i> কপি হয়েছে!'; setTimeout(() => { btn.innerHTML = t; }, 1500); }
            });
        };

        if (baProceedBtn) {
            baProceedBtn.addEventListener('click', () => {
                baInfoStep?.classList.remove('active');
                baPayStep?.classList.add('active');
                baUpdateMerchant();
            });
        }
        if (baBackToInfo) {
            baBackToInfo.addEventListener('click', () => {
                baPayStep?.classList.remove('active');
                baInfoStep?.classList.add('active');
                if (baFeedback) { baFeedback.classList.add('hidden'); baFeedback.textContent = ''; }
            });
        }
        // Reset step when modal opened
        document.querySelectorAll('[data-open-modal="becomeAgentModal"]').forEach(btn => {
            btn.addEventListener('click', () => {
                setTimeout(() => {
                    baInfoStep?.classList.add('active');
                    baPayStep?.classList.remove('active');
                    if (baFeedback) { baFeedback.classList.add('hidden'); baFeedback.textContent = ''; }
                }, 10);
            });
        });

        // Payment method card selection
        document.querySelectorAll('.ba-method-card').forEach(card => {
            card.addEventListener('click', () => {
                document.querySelectorAll('.ba-method-card').forEach(c => {
                    c.classList.remove('active');
                    c.style.borderColor = ''; c.style.background = '';
                });
                card.classList.add('active');
                const m = card.getAttribute('data-method');
                const d = _baPmData()[m];
                const colors = {bkash:'#E2136E', nagad:'#E8522E', rocket:'#CC0000'};
                const bgColors = {bkash:'rgba(226,19,110,.05)', nagad:'rgba(232,82,46,.05)', rocket:'rgba(204,0,0,.05)'};
                if (d) { 
                    card.style.borderColor = colors[m]; 
                    card.style.background = bgColors[m]; 
                }
                baCurrentMethod = m;
                baUpdateMerchant();
            });
        });

        // Payment submit
        if (baPayBtn) {
            baPayBtn.addEventListener('click', () => {
                const phone = (baPhone ? baPhone.value : '').trim();
                const txid = (baTxid ? baTxid.value : '').trim().toUpperCase();
                if (!/^01[3-9]\d{8}$/.test(phone)) {
                    if (baFeedback) { baFeedback.classList.remove('hidden'); baFeedback.textContent = 'Please enter a valid Bangladeshi phone number (01XXXXXXXXX).'; baFeedback.style.color = '#dc2626'; }
                    return;
                }
                if (txid.length < 4) {
                    if (baFeedback) { baFeedback.classList.remove('hidden'); baFeedback.textContent = 'Please enter a valid Transaction ID.'; baFeedback.style.color = '#dc2626'; }
                    return;
                }
                baPayBtn.disabled = true;
                baPayBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                if (baFeedback) { baFeedback.classList.add('hidden'); baFeedback.textContent = ''; }
                this._api({
                    action: 'submit_payment',
                    method: baCurrentMethod,
                    sender_phone: phone,
                    transaction_id: txid,
                    amount: 500,
                    purpose: 'agent_activation',
                    csrf_token: csrf
                }).then(res => {
                    if (res.success) {
                        this._showBaResult(true, '✅ পেমেন্ট সাবমিট!', 'পেমেন্ট সাবমিট হয়েছে! অ্যাডমিন ভেরিফাই করে আপনার একাউন্ট এক্টিভেট করবে।');
                    } else {
                        baPayBtn.disabled = false;
                        baPayBtn.innerHTML = '<i class="fas fa-paper-plane"></i> সাবমিট করুন';
                        this._showBaResult(false, '❌ সাবমিট ব্যর্থ', res.message || 'দয়া করে আবার চেষ্টা করুন।');
                    }
                }).catch(() => {
                    baPayBtn.disabled = false;
                    baPayBtn.innerHTML = '<i class="fas fa-paper-plane"></i> সাবমিট করুন';
                    this._showBaResult(false, '❌ নেটওয়ার্ক এরর', 'সার্ভারে সংযোগ করা যায়নি। দয়া করে আবার চেষ্টা করুন।');
                });
            });
        }

        // Init custom selects + re-init when any modal opens
        if (typeof initAllCustomSelects === 'function') initAllCustomSelects(); else if (typeof window.initAllCustomSelects === 'function') window.initAllCustomSelects();
        document.querySelectorAll('[data-open-modal]').forEach(btn => {
            btn.addEventListener('click', () => {
                setTimeout(() => {
                    if (typeof initAllCustomSelects === 'function') initAllCustomSelects(); else if (typeof window.initAllCustomSelects === 'function') window.initAllCustomSelects();
                }, 50);
            });
        });

        // Profile Edit Tab Switching
        document.querySelectorAll('.gp-form-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.tab;
                const modal = tab.closest('.gp-modal');
                if (!modal) return;
                modal.querySelectorAll('.gp-form-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                modal.querySelectorAll('.gp-tab-content').forEach(c => c.classList.remove('active'));
                const content = modal.querySelector(`#tab-${target}`);
                if (content) content.classList.add('active');
                setTimeout(() => {
                    if (typeof initAllCustomSelects === 'function') initAllCustomSelects(); else if (typeof window.initAllCustomSelects === 'function') window.initAllCustomSelects();
                }, 20);
            });
        });
    }

    initGPUnregister() {
        document.querySelectorAll('.gp-unregister').forEach(btn => {
            btn.addEventListener('click', async () => {
                const fee = parseFloat(btn.getAttribute('data-fee') || '0');
                const confirmed = await this._gpConfirmLeave(fee);
                if (!confirmed) return;
                const tid = btn.getAttribute('data-id');
                const csrf = document.getElementById('tournamentsPage')?.getAttribute('data-csrf') || '';
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                const result = await this._api({ action: 'unregister', tournament_id: tid, csrf_token: csrf });
                if (result.success) { this._toast(result.message); setTimeout(() => window.location.reload(), 800); }
                else { this._toast(result.message, 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-xmark"></i>'; }
            });
        });
    }

    _gpConfirmLeave(fee) {
        return new Promise(resolve => {
            const ov = document.createElement('div');
            ov.style.cssText = 'position:fixed;inset:0;background:var(--bg-overlay,rgba(0,0,0,.5));z-index:99998;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center';
            document.body.appendChild(ov);
            document.body.style.overflow = 'hidden';

            const p = document.createElement('div');
            p.style.cssText = 'background:var(--bg-card,#fff);border-radius:20px;padding:24px;max-width:400px;width:90%;box-shadow:var(--shadow-2xl,0 25px 50px -12px rgba(0,0,0,.25));opacity:0;transform:translateY(30px) scale(.97);transition:opacity .35s cubic-bezier(.34,1.56,.64,1),transform .35s cubic-bezier(.34,1.56,.64,1)';
            const feeHtml = fee > 0
                ? '<div style="margin:14px 0 0;padding:12px 14px;border-radius:12px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.15)"><div style="font-size:.8rem;color:var(--green,#10b981);font-weight:700;margin-bottom:2px"><i class="fas fa-coins"></i> Refund details</div><div style="font-size:.76rem;color:var(--text-muted,#64748b);line-height:1.5">Entry fee of \u09F3' + fee.toFixed(0) + ' will be refunded to your balance.</div></div>'
                : '';
            p.innerHTML = '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px"><i class="fas fa-question-circle" style="color:var(--warning,#f59e0b);font-size:1.2rem"></i><h3 style="margin:0;font-size:1rem;font-weight:700;color:var(--text-primary,#0f172a)">Leave tournament?</h3></div>'
                + '<div style="font-size:.82rem;color:var(--text-muted,#64748b);line-height:1.65;margin-bottom:2px">By leaving you will:</div>'
                + '<ul style="font-size:.78rem;color:var(--text-secondary,#475569);line-height:1.85;margin:8px 0 0;padding-left:16px;list-style:none">'
                + '<li style="position:relative;padding-left:18px;margin-bottom:4px"><span style="position:absolute;left:0;color:#ef4444">\u2716</span> Be removed from the tournament</li>'
                + '<li style="position:relative;padding-left:18px;margin-bottom:4px"><span style="position:absolute;left:0;color:#ef4444">\u2716</span> Free up your slot for other players</li>'
                + (fee > 0 ? '<li style="position:relative;padding-left:18px"><span style="position:absolute;left:0;color:var(--green,#10b981)">\u2714</span> Get \u09F3' + fee.toFixed(0) + ' refunded to your balance</li>' : '')
                + '</ul>'
                + feeHtml
                + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px;padding-top:14px;border-top:1px solid var(--border,#e2e8f0)">'
                + '<button class="gp-btn gp-btn-ghost" id="_gpcCancel" style="min-width:90px;justify-content:center">Cancel</button>'
                + '<button class="gp-btn gp-btn-primary" id="_gpcOk" style="min-width:90px;justify-content:center"><i class="fas fa-check"></i> Leave</button>'
                + '</div>';
            ov.appendChild(p);

            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    p.style.opacity = '1';
                    p.style.transform = 'translateY(0) scale(1)';
                });
            });

            const cleanup = () => { ov.remove(); document.body.style.overflow = ''; };
            document.getElementById('_gpcOk').onclick = () => { cleanup(); resolve(true); };
            const nope = () => { cleanup(); resolve(false); };
            document.getElementById('_gpcCancel').onclick = nope;
            ov.addEventListener('click', e => { if (e.target === ov) nope(); });
        });
    }

    initGPViewParticipants() {
        document.querySelectorAll('.gp-view-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tid = btn.getAttribute('data-id');
                const list = document.getElementById('participantsList');
                const overlay = document.getElementById('gpOverlay');
                const modal = document.getElementById('viewParticipantsModal');
                if (!list || !modal) return;
                if (overlay) overlay.classList.remove('hidden');
                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
                list.innerHTML = '<div class="gp-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

                const csrf = document.getElementById('tournamentsPage')?.getAttribute('data-csrf') || '';
                const result = await this._api({ action: 'get_participants', tournament_id: tid, csrf_token: csrf });

                if (result.success && result.participants?.length) {
                    const uid = parseInt(document.getElementById('tournamentsPage')?.getAttribute('data-user-id') || '0', 10);
                    list.innerHTML = '<div class="gp-participants">' + result.participants.map((p, i) => {
                        const n = p.full_name || p.username || 'Player';
                        const a = p.avatar || 'default.png';
                        return `<div class="gp-participant"><img src="assets/avatars/${this.escHtml(a)}" alt="" onerror="this.src='assets/avatars/default.png'"><div class="gp-participant-info"><strong>${this.escHtml(n)}${parseInt(p.user_id,10) === uid ? ' <span class="text-purple-500">(you)</span>' : ''}</strong><span>${p.team_name ? 'Team: '+this.escHtml(p.team_name) : 'Solo'}</span></div><span class="text-gray-400 text-xs">#${i+1}</span></div>`;
                    }).join('') + '</div>';
                } else {
                    list.innerHTML = '<div class="gp-loading" style="padding:3rem"><i class="fas fa-users text-2xl mb-2 block opacity-40"></i><p>No participants yet.</p></div>';
                }
            });
        });
    }

    initGPTeamDelete() {
        document.querySelectorAll('.gp-delete-team').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tid = btn.getAttribute('data-team-id');
                const tname = btn.getAttribute('data-team-name');
                const confirmed = await this._gpConfirmDeleteTeam(tname);
                if (!confirmed) return;
                const csrf = document.getElementById('tournamentsPage')?.getAttribute('data-csrf') || '';
                btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                const result = await this._api({ action: 'delete_team', team_id: tid, csrf_token: csrf });
                if (result.success) { this._toast(result.message); setTimeout(() => window.location.reload(), 800); }
                else { this._toast(result.message, 'error'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-trash-can"></i>'; }
            });
        });
    }

    _gpConfirmDeleteTeam(teamName) {
        return new Promise(resolve => {
            const ov = document.createElement('div');
            ov.style.cssText = 'position:fixed;inset:0;background:var(--bg-overlay,rgba(0,0,0,.5));z-index:99998;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center';
            document.body.appendChild(ov);
            document.body.style.overflow = 'hidden';

            const p = document.createElement('div');
            p.style.cssText = 'background:var(--bg-card,#fff);border-radius:20px;padding:24px;max-width:400px;width:90%;box-shadow:var(--shadow-2xl,0 25px 50px -12px rgba(0,0,0,.25));opacity:0;transform:translateY(30px) scale(.97);transition:opacity .35s cubic-bezier(.34,1.56,.64,1),transform .35s cubic-bezier(.34,1.56,.64,1)';
            const safeName = this.escHtml(teamName);
            p.innerHTML = '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px"><i class="fas fa-exclamation-triangle" style="color:#dc2626;font-size:1.2rem"></i><h3 style="margin:0;font-size:1rem;font-weight:700;color:var(--text-primary,#0f172a)">Delete <span style="color:#dc2626">' + safeName + '</span>?</h3></div>'
                + '<div style="font-size:.82rem;color:var(--text-muted,#64748b);line-height:1.65;margin-bottom:2px">This action <strong>cannot</strong> be undone:</div>'
                + '<ul style="font-size:.78rem;color:var(--text-secondary,#475569);line-height:1.85;margin:8px 0 0;padding-left:16px;list-style:none">'
                + '<li style="position:relative;padding-left:18px;margin-bottom:4px"><span style="position:absolute;left:0;color:#ef4444">\u2716</span> All team members will be removed</li>'
                + '<li style="position:relative;padding-left:18px;margin-bottom:4px"><span style="position:absolute;left:0;color:#ef4444">\u2716</span> Tournament registrations will be cancelled</li>'
                + '<li style="position:relative;padding-left:18px"><span style="position:absolute;left:0;color:#ef4444">\u2716</span> Match history will be cleared</li>'
                + '</ul>'
                + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px;padding-top:14px;border-top:1px solid var(--border,#e2e8f0)">'
                + '<button class="gp-btn gp-btn-ghost" id="_gpdcCancel" style="min-width:90px;justify-content:center">Cancel</button>'
                + '<button class="gp-btn" id="_gpdcOk" style="min-width:90px;justify-content:center;background:#dc2626;color:#fff;border:0"><i class="fas fa-trash-can"></i> Delete</button>'
                + '</div>';
            ov.appendChild(p);

            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    p.style.opacity = '1';
                    p.style.transform = 'translateY(0) scale(1)';
                });
            });

            const cleanup = () => { ov.remove(); document.body.style.overflow = ''; };
            document.getElementById('_gpdcOk').onclick = () => { cleanup(); resolve(true); };
            const nope = () => { cleanup(); resolve(false); };
            document.getElementById('_gpdcCancel').onclick = nope;
            ov.addEventListener('click', e => { if (e.target === ov) nope(); });
        });
    }

    initGPTeamManage() {
        document.querySelectorAll('.gp-manage-team').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tid = btn.getAttribute('data-team-id');
                const tname = btn.getAttribute('data-team-name');
                const overlay = document.getElementById('gpOverlay');
                const modal = document.getElementById('teamManageModal');
                const body = document.getElementById('teamManageBody');
                const title = document.getElementById('teamManageTitle');
                if (!modal || !body) return;

                if (title) title.textContent = tname;
                if (overlay) overlay.classList.remove('hidden');
                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
                body.innerHTML = '<div class="gp-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

                const csrf = document.getElementById('tournamentsPage')?.getAttribute('data-csrf') || '';
                const result = await this._api({ action: 'get_team_members', team_id: tid, csrf_token: csrf });

                if (result.success && result.members?.length) {
                    const uid = parseInt(document.getElementById('tournamentsPage')?.getAttribute('data-user-id') || '0', 10);
                    const isCaptain = result.members.some(m => parseInt(m.id,10) === uid && m.role === 'captain');
                    let html = '<div class="gp-team-members">';
                    result.members.forEach(m => {
                        const n = m.full_name || m.username || 'Player';
                        const a = m.avatar || 'default.png';
                        const isMe = parseInt(m.id,10) === uid;
                        html += `<div class="gp-team-member"><img src="assets/avatars/${this.escHtml(a)}" alt="" onerror="this.src='assets/avatars/default.png'"><div class="gp-team-member-info"><strong>${this.escHtml(n)}${isMe ? ' (you)' : ''}</strong><span class="gp-team-member-role">${m.role}</span></div>`;
                        if (isCaptain && !isMe && m.role !== 'captain') {
                            html += `<button class="gp-btn gp-btn-xs gp-btn-ghost gp-remove-member" data-team="${tid}" data-user="${m.id}" style="color:#dc2626"><i class="fas fa-user-minus"></i></button>`;
                        }
                        html += '</div>';
                    });
                    html += '</div>';
                    if (isCaptain) {
                        html += `<div class="gp-team-add"><input type="text" class="gp-input gp-add-member-input" placeholder="Enter user ID or username" data-team="${tid}"><button class="gp-btn gp-btn-sm gp-btn-primary gp-add-member-btn" data-team="${tid}"><i class="fas fa-plus"></i> Add</button></div>`;
                    }
                    body.innerHTML = html;

                    // Remove member handler
                    body.querySelectorAll('.gp-remove-member').forEach(rb => {
                        rb.addEventListener('click', async () => {
                            if (!confirm('Remove this member?')) return;
                            const res = await this._api({ action: 'remove_member', team_id: rb.getAttribute('data-team'), member_id: rb.getAttribute('data-user'), csrf_token: csrf });
                            if (res.success) { this._toast(res.message); setTimeout(() => window.location.reload(), 800); }
                            else { this._toast(res.message, 'error'); }
                        });
                    });

                    // Add member handler
                    body.querySelectorAll('.gp-add-member-btn').forEach(ab => {
                        ab.addEventListener('click', async () => {
                            const input = body.querySelector('.gp-add-member-input');
                            const val = input?.value?.trim();
                            if (!val) return;
                            const res = await this._api({ action: 'add_member', team_id: ab.getAttribute('data-team'), member_id: parseInt(val,10) || val, csrf_token: csrf });
                            if (res.success) { this._toast(res.message); setTimeout(() => window.location.reload(), 800); }
                            else { this._toast(res.message, 'error'); }
                        });
                    });
                } else {
                    body.innerHTML = '<div class="gp-loading">No members.</div>';
                }
            });
        });
    }

    initGPHistory() {
        document.querySelectorAll('[data-open-modal="agentHistoryModal"]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const body = document.getElementById('agentHistoryBody');
                const csrf = document.getElementById('tournamentsPage')?.getAttribute('data-csrf') || '';
                if (!body) return;
                body.innerHTML = '<div class="gp-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

                const result = await this._api({ action: 'agent_stats', csrf_token: csrf });

                if (result.success && result.transactions?.length) {
                    let html = '<div class="gp-txns">';
                    result.transactions.forEach(t => {
                        const isCredit = t.type === 'credit';
                        const icon = isCredit ? 'fa-arrow-down' : 'fa-arrow-up';
                        const amt = `${isCredit ? '+' : '-'}$${parseFloat(t.amount).toFixed(2)}`;
                        const desc = t.description || t.reference_type || '';
                        const date = t.created_at ? new Date(t.created_at).toLocaleDateString() : '';
                        html += `<div class="gp-txn-item"><span class="gp-txn-icon ${t.type}"><i class="fas ${icon}"></i></span><div class="gp-txn-info"><strong>${this.escHtml(desc)}</strong><span>${date}</span></div><span class="gp-txn-amount ${t.type}">${amt}</span></div>`;
                    });
                    html += '</div>';
                    body.innerHTML = html;
                } else {
                    body.innerHTML = '<div class="gp-loading">No transactions yet.</div>';
                }
            });
        });
    }

    initGPAmountButtons() {
        document.querySelectorAll('.gp-amount-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.gp-amount-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const input = document.getElementById('customAmount');
                if (input) input.value = btn.getAttribute('data-amount');
            });
        });
    }

    initGPIcons() {
        document.querySelectorAll('.gp-icon-opt').forEach(btn => {
            btn.addEventListener('click', () => {
                btn.closest('.gp-icon-picker')?.querySelectorAll('.gp-icon-opt').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const input = btn.closest('.gp-form-group')?.querySelector('input[type="hidden"]');
                if (input) input.value = btn.getAttribute('data-icon') || '';
            });
        });
    }

    initGPColors() {
        document.querySelectorAll('.gp-color-opt').forEach(btn => {
            btn.addEventListener('click', () => {
                btn.closest('.gp-color-picker')?.querySelectorAll('.gp-color-opt').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const input = btn.closest('.gp-form-group')?.querySelector('input[type="hidden"]');
                if (input) input.value = btn.getAttribute('data-color') || '';
            });
        });
    }

    initGPScroll() {
        document.querySelectorAll('[data-scroll-to]').forEach(link => {
            link.addEventListener('click', (e) => {
                const target = link.getAttribute('data-scroll-to');
                const el = document.getElementById(target);
                if (el) { e.preventDefault(); el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            });
        });
    }

    initCartPage() {
        
        this.initCartPageFunctionality();
    }

    initHeroSlider(slider) {
        
    }

    initFeaturedProducts() {
        
    }

    initProfileTabs() {
        
    }

    initProductFilters() {
        
    }

    initProductGrid() {
        
    }

    initCartPageFunctionality() {
        
    }
}

// Initialize app when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    
    
    // Check if app should be initialized
    const disableApp = document.documentElement.hasAttribute('data-no-app');
    
    if (!disableApp && !window.appInitialized) {
        // Create instance immediately
        window.DreamBD = new DreamBDApp();
        
        // Start initialization with a delay to ensure all scripts are loaded
        setTimeout(() => {
            if (window.DreamBD && !window.appInitialized) {
                window.DreamBD.init().then(() => {
                    window.appInitialized = true;
                    
                }).catch(error => {
                    
                });
            }
        }, 100);
    }
});

// Public API
window.navigateToPage = function(page) {
    if (window.AjaxNavigation) {
        return window.AjaxNavigation.navigate(page);
    } else {
        // Fallback to traditional navigation
        window.location.href = `?page=${page}`;
    }
};

window.addToCart = function(product) {
    if (window.DreamBD && window.DreamBD.addToCart) {
        return window.DreamBD.addToCart(product);
    }
    return null;
};

window.getCart = function() {
    if (window.DreamBD && window.DreamBD.components?.cart) {
        return window.DreamBD.components.cart;
    }
    return { items: [], total: 0, count: 0 };
};

// Debugging helpers (remove in production)
if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
    window.debugApp = function() {
        if (window.DreamBD) {
            
        }
        
        if (window.AjaxNavigation) {
            
        }
    };
}

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = DreamBDApp;
}
