(function () {
class HomePage {
    constructor() {
        this.isInitialized = false;
        this.currentSlide = 0;
        this.totalSlides = 0;
        this.autoSlideTimer = null;
        this.autoSlideInterval = 5500;
        this.sliderHandlersBound = false;
    }

    async init() {
        if (this.isInitialized) return;
        try {
            await this.waitForDOM();
            this.initSlider();
            this.initStatsCounter();
            this.initHeroScroll();
            this.initPostMenus();
            this.initFriendActions();
            this.isInitialized = true;
            document.dispatchEvent(new CustomEvent('homePageReady'));
        } catch (e) {}
    }

    waitForDOM() {
        return new Promise((resolve) => {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', resolve, { once: true });
            } else {
                resolve();
            }
        });
    }

    initSlider() {
        const slider = document.querySelector('.home-hero-slider');
        const slides = Array.from(document.querySelectorAll('.home-slide'));
        const dots = Array.from(document.querySelectorAll('.home-slider-dot'));
        const prevBtn = document.querySelector('.home-slider-prev');
        const nextBtn = document.querySelector('.home-slider-next');
        const progressBar = document.querySelector('.home-slider-progress-bar');

        if (!slider || !slides.length) return;

        this.totalSlides = slides.length;
        this.currentSlide = Math.max(0, slides.findIndex((slide) => slide.classList.contains('active')));
        if (this.currentSlide < 0) this.currentSlide = 0;

        const updateProgress = () => {
            if (!progressBar) return;
            const width = `${100 / this.totalSlides}%`;
            progressBar.style.width = width;
            progressBar.style.transform = `translateX(${this.currentSlide * 100}%)`;
        };

        const resetDotProgress = () => {
            dots.forEach((dot, index) => {
                const indicator = dot.querySelector('.home-slider-dot-progress');
                if (!indicator) return;
                indicator.style.transition = 'none';
                indicator.style.width = index === this.currentSlide ? '0%' : '0%';
                void indicator.offsetWidth;
                if (index === this.currentSlide) {
                    indicator.style.transition = `width ${this.autoSlideInterval}ms linear`;
                    indicator.style.width = '100%';
                }
            });
        };

        const showSlide = (index) => {
            this.currentSlide = (index + this.totalSlides) % this.totalSlides;
            slides.forEach((slide, slideIndex) => {
                slide.classList.toggle('active', slideIndex === this.currentSlide);
            });
            dots.forEach((dot, dotIndex) => {
                dot.classList.toggle('active', dotIndex === this.currentSlide);
            });
            updateProgress();
            resetDotProgress();
        };

        const nextSlide = () => showSlide(this.currentSlide + 1);
        const prevSlideFn = () => showSlide(this.currentSlide - 1);

        const startAutoSlide = () => {
            this.stopAutoSlide();
            this.autoSlideTimer = window.setInterval(nextSlide, this.autoSlideInterval);
        };

        const restartAutoSlide = () => {
            showSlide(this.currentSlide);
            startAutoSlide();
        };

        if (!this.sliderHandlersBound) {
            prevBtn?.addEventListener('click', (e) => { e.preventDefault(); prevSlideFn(); startAutoSlide(); });
            nextBtn?.addEventListener('click', (e) => { e.preventDefault(); nextSlide(); startAutoSlide(); });
            dots.forEach((dot, i) => { dot.addEventListener('click', (e) => { e.preventDefault(); showSlide(i); startAutoSlide(); }); });
            slider.addEventListener('mouseenter', () => this.stopAutoSlide());
            slider.addEventListener('mouseleave', startAutoSlide);
            this.sliderHandlersBound = true;
        }
        restartAutoSlide();
    }

    stopAutoSlide() {
        if (this.autoSlideTimer) {
            clearInterval(this.autoSlideTimer);
            this.autoSlideTimer = null;
        }
    }

    initStatsCounter() {
        const stats = document.querySelectorAll('.home-stat-number');
        if (!stats.length) return;
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                const stat = entry.target;
                const target = Number(stat.dataset.count || 0);
                const startTime = performance.now();
                let finished = false;
                // Safety net: rAF can stall (throttled/background tab) which
                // would freeze the number mid-count. Timers still run.
                const finish = () => {
                    if (finished) return;
                    finished = true;
                    stat.textContent = target.toLocaleString();
                };
                const tick = (now) => {
                    if (finished) return;
                    const progress = Math.min((now - startTime) / 1400, 1);
                    stat.textContent = Math.floor(target * (1 - Math.pow(1 - progress, 4))).toLocaleString();
                    if (progress < 1) requestAnimationFrame(tick);
                    else finish();
                };
                requestAnimationFrame(tick);
                window.setTimeout(finish, 1700);
                observer.unobserve(stat);
            });
        }, { threshold: 0.35 });
        stats.forEach((s) => observer.observe(s));
    }

    initHeroScroll() {
        if (window.__heroScrollBound) return;
        window.__heroScrollBound = true;
        document.addEventListener('click', (e) => {
            const link = e.target.closest('[data-hero-scroll]');
            if (!link) return;
            const selector = link.getAttribute('data-hero-scroll');
            const target = selector ? document.querySelector(selector) : null;
            const scroller = document.getElementById('mainContent');
            if (!target || !scroller) return;
            e.preventDefault();
            const offset = target.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop - 96;
            scroller.scrollTo({ top: Math.max(0, offset), behavior: 'smooth' });
        });
    }

    initPostMenus() {
        if (window.__homePostMenusBound) return;
        window.__homePostMenusBound = true;
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.home-btn-post-menu, .btn-ghost');
            if (btn) {
                const dd = btn.nextElementSibling;
                if (dd && dd.classList.contains('home-post-menu-dropdown')) {
                    e.stopImmediatePropagation();
                    document.querySelectorAll('.home-post-menu-dropdown.show').forEach(m => { if(m!==dd) m.classList.remove('show'); });
                    dd.classList.toggle('show');
                }
            } else if (!e.target.closest('.home-post-menu-dropdown')) {
                document.querySelectorAll('.home-post-menu-dropdown.show').forEach(m => m.classList.remove('show'));
            }
        });
    }

    initFriendActions() {
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('.friend-toggle-btn[data-action="send_friend_request"]');
            if (!btn) return;
            e.preventDefault();
            const userId = btn.dataset.targetUserId;
            const csrfToken = document.querySelector('[data-home-suggestions]')?.dataset.csrfToken || document.body.dataset.csrfToken;
            const fd = new FormData();
            fd.append('action', 'send_friend_request');
            fd.append('target_user_id', userId);
            fd.append('csrf_token', csrfToken);
            try {
                const r = await fetch('handlers/profile_handlers.php', { method: 'POST', body: fd });
                const d = await r.json();
                if (d.success) {
                    const card = btn.closest('[data-suggestion-card]');
                    if (card) card.remove();
                }
            } catch (err) {}
        });
    }
}

const initHomePage = (force = false) => {
    if (!force && window.homePageInitialized) return;
    window.HomePage = new HomePage();
    window.HomePage.init().then(() => window.homePageInitialized = true);
};

const maybeInitHomePage = (force = false) => {
    if (document.querySelector('.home-facebook-layout, .home-hero-slider')) initHomePage(force);
};

window.initHomePage = initHomePage;
window.maybeInitHomePage = maybeInitHomePage;

if (!window.__homePageListenersAttached) {
    window.__homePageListenersAttached = true;
    document.addEventListener('DOMContentLoaded', () => maybeInitHomePage());
    document.addEventListener('pageChanged', () => setTimeout(() => maybeInitHomePage(true), 200));
}
if (document.readyState !== 'loading') maybeInitHomePage();
})();
