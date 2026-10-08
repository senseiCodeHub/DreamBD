// ===== COMMUNITY INTERACTION HANDLER =====

function escapeHtml(text) {
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}

(function() {
    // 1. Global Event Delegation (Attached only once to document)
    if (!window.communityGlobalListenerAttached) {
        window.communityGlobalListenerAttached = true;
        

        document.addEventListener('click', async (e) => {
            // A. Reaction Picker Buttons
            const reactionBtn = e.target.closest('.community-reaction-btn');
            if (reactionBtn) {
                e.preventDefault();
                const postCard = reactionBtn.closest('.community-post-card');
                if (!postCard) return;
                const postId = postCard.dataset.postId;
                const reaction = reactionBtn.dataset.reaction;
                await submitReaction(postId, reaction);
                return;
            }

            // B. Main Action Buttons (Like, Comment, Share)
            const actionBtn = e.target.closest('.community-btn-action');
            if (actionBtn) {
                const action = actionBtn.dataset.action;
                
                if (action === 'comment') {
                    const postId = actionBtn.dataset.postId;
                    if (postId) {
                        e.preventDefault();
                        e.stopPropagation();
                        openPostModal(postId);
                    }
                    return;
                }

                if (action === 'like' && !e.target.closest('.community-reaction-strip')) {
                    e.preventDefault();
                    const postCard = actionBtn.closest('.community-post-card');
                    if (!postCard) return;
                    const postId = postCard.dataset.postId;
                    const currentReaction = actionBtn.dataset.reaction;
                    const reaction = currentReaction ? currentReaction : 'like';
                    await submitReaction(postId, reaction);
                    return;
                }

                if (action === 'share') {
                    e.preventDefault();
                    const postCard = actionBtn.closest('.community-post-card');
                    if (!postCard) return;
                    const postId = postCard.dataset.postId;
                    await sharePost(postId, actionBtn);
                    return;
                }
            }

            // C. Comment Triggers (Count Span or Button)
            const commentTrigger = e.target.closest('.community-comment-trigger');
            if (commentTrigger && !commentTrigger.classList.contains('community-btn-action')) {
                const postId = commentTrigger.getAttribute('data-post-id');
                if (postId) {
                    e.preventDefault();
                    e.stopPropagation();
                    openPostModal(postId);
                }
                return;
            }

            // D. Comment Like Button (In Modal)
            const commentLikeBtn = e.target.closest('.comment-like-btn');
            if (commentLikeBtn) {
                const commentId = commentLikeBtn.closest('.community-comment')?.dataset.commentId;
                if (commentId) {
                    e.preventDefault();
                    await reactComment(commentId);
                }
                return;
            }

            // E1. Comment Reply Button (In Modal)
            const commentReplyBtn = e.target.closest('.comment-reply-trigger');
            if (commentReplyBtn) {
                const commentId = commentReplyBtn.closest('.community-comment')?.dataset.commentId;
                const username = commentReplyBtn.dataset.username;
                if (commentId && username) {
                    e.preventDefault();
                    focusReply(commentId, username);
                }
                return;
            }

            // E2. View All Replies Button
            const viewAllBtn = e.target.closest('.view-all-replies-btn');
            if (viewAllBtn) {
                e.preventDefault();
                const commentId = viewAllBtn.dataset.commentId;
                if (commentId) {
                    const list = document.getElementById(`replies-${commentId}`);
                    if (list) {
                        const wrap = list.querySelector('.reply-hidden-wrap');
                        if (wrap) wrap.style.display = '';
                        viewAllBtn.closest('.view-all-replies-wrap')?.remove();
                    }
                }
                return;
            }

            // F. Post Menu Trigger (3-dot)
            const menuTrigger = e.target.closest('.post-menu-trigger');
            if (menuTrigger) {
                e.preventDefault();
                e.stopPropagation();
                // Close all other open dropdowns
                document.querySelectorAll('.post-dropdown.visible').forEach(d => { if (d !== menuTrigger.nextElementSibling) d.classList.remove('visible'); });
                const dropdown = menuTrigger.nextElementSibling;
                if (dropdown) dropdown.classList.toggle('visible');
                return;
            }

            // G. Post Menu Item Click
            const menuItem = e.target.closest('.post-dropdown-item');
            if (menuItem) {
                e.preventDefault();
                const action = menuItem.dataset.action;
                const postId = menuItem.dataset.postId;
                const dropdown = menuItem.closest('.post-dropdown');
                if (dropdown) dropdown.classList.remove('visible');

                if (action === 'edit') {
                    handleEditPost(postId);
                } else if (action === 'delete') {
                    handleDeletePost(postId);
                } else if (action === 'save') {
                    handleSavePost(postId, menuItem);
                } else if (action === 'report') {
                    handleReportPost(postId);
                }
                return;
            }

            // H. Friend request Confirm / Delete (right rail)
            const frBtn = e.target.closest('[data-fr-action]');
            if (frBtn) {
                e.preventDefault();
                const card = frBtn.closest('[data-fr-card]');
                if (!card || frBtn.disabled) return;
                const decision = frBtn.dataset.frAction === 'accept' ? 'accept' : 'reject';
                frBtn.disabled = true;
                frBtn.style.opacity = '.6';
                const fd = new FormData();
                fd.append('action', 'respond_friend_request');
                fd.append('request_user_id', card.dataset.userId || '');
                fd.append('decision', decision);
                fd.append('csrf_token', getCsrfToken());
                try {
                    const r = await fetch('handlers/profile_handlers.php', { method: 'POST', body: fd });
                    const d = await r.json();
                    if (!d.success) throw new Error(d.message || 'Request failed');
                    card.style.transition = 'opacity .25s ease, transform .25s ease';
                    card.style.opacity = '0';
                    card.style.transform = 'translateX(20px)';
                    setTimeout(() => { card.remove(); refreshFriendRequestBadge(); }, 250);
                    cmToast(decision === 'accept' ? 'Friend request accepted' : 'Request removed');
                } catch (err) {
                    frBtn.disabled = false;
                    frBtn.style.opacity = '';
                    cmToast(err.message || 'Request failed', true);
                }
                return;
            }

            // I. Suggestion Add friend / Dismiss (right rail)
            const sgBtn = e.target.closest('[data-sg-action]');
            if (sgBtn) {
                e.preventDefault();
                const card = sgBtn.closest('[data-sg-card]');
                if (!card || sgBtn.disabled) return;
                const mode = sgBtn.dataset.sgAction;
                sgBtn.disabled = true;
                const fd = new FormData();
                fd.append('action', mode === 'add' ? 'send_friend_request' : 'dismiss_suggestion');
                fd.append('target_user_id', card.dataset.userId || '');
                fd.append('csrf_token', getCsrfToken());
                try {
                    const r = await fetch('handlers/profile_handlers.php', { method: 'POST', body: fd });
                    const d = await r.json();
                    if (!d.success) throw new Error(d.message || 'Something went wrong');
                    card.style.transition = 'opacity .25s ease, transform .25s ease';
                    card.style.opacity = '0';
                    card.style.transform = mode === 'add' ? 'scale(.94)' : 'translateX(20px)';
                    setTimeout(() => card.remove(), 250);
                    cmToast(mode === 'add' ? 'Friend request sent' : 'Suggestion removed');
                } catch (err) {
                    sgBtn.disabled = false;
                    cmToast(err.message || 'Something went wrong', true);
                }
                return;
            }

            // J. Hero / empty-state CTAs
            const heroCta = e.target.closest('#heroCreatePost, #cmFeedEmptyCreate');
            if (heroCta) {
                e.preventDefault();
                const trigger = document.getElementById('composerTriggerBtn');
                if (trigger) trigger.click();
                return;
            }

            // Close all post dropdowns on outside click
            if (!e.target.closest('.post-menu-container')) {
                document.querySelectorAll('.post-dropdown.visible').forEach(d => d.classList.remove('visible'));
            }
        });
    }

    // 2. Initialization Function (Runs on every page load/AJAX)
    window.initCommunity = () => {
        
        const pageEl = document.querySelector('[data-community-page]') || document.querySelector('[data-profile-page]') || document.querySelector('[data-home-page]');
        if (pageEl) window.__communityViewerId = pageEl.dataset.viewerId;
        loadCommunityContacts();
        initComposer();
        initEditModal();
        initReportModal();
        initFeedToolbar();

        // Check URL for post/comment parameters (e.g. from notification redirect)
        const params = new URLSearchParams(window.location.search);
        const postId = params.get('post');
        const reportMsg = params.get('report_msg');
        if (postId) {
            setTimeout(() => {
                openPostModal(postId);
                if (reportMsg) {
                    setTimeout(() => {
                        showStatusModal('info', 'Report Review', decodeURIComponent(reportMsg));
                    }, 600);
                }
            }, 300);
        }
    };

    // ===== COMPOSER LOGIC =====
    const COMMUNITY_REACTIONS = [
        { type: 'like', label: 'Like', emoji: '👍' },
        { type: 'love', label: 'Love', emoji: '❤️' },
        { type: 'care', label: 'Care', emoji: '🥰' },
        { type: 'haha', label: 'Haha', emoji: '😆' },
        { type: 'wow', label: 'Wow', emoji: '😮' },
        { type: 'sad', label: 'Sad', emoji: '😢' },
        { type: 'angry', label: 'Angry', emoji: '😡' },
    ];

    function initComposer() {
        // Remove any stale overlay in body from a prior page load
        const stale = document.querySelector('body > #createPostOverlay');
        if (stale) stale.remove();

        let overlay = document.getElementById('createPostOverlay');
        if (!overlay) return;

        // Prevent duplicate init on the same element (e.g. pageChanged fired twice)
        if (overlay.dataset.composerInit === '1') return;
        overlay.dataset.composerInit = '1';

        document.body.appendChild(overlay);
        const closeBtn = document.getElementById('createPostClose');
        const textarea = document.getElementById('createPostTextarea');
        const submitBtn = document.getElementById('createPostSubmit');
        const photoBtn = document.getElementById('createPostPhotoBtn');
        const photoInput = document.getElementById('createPostPhotoInput');
        const photoPreview = document.getElementById('createPostPhotoPreview');
        const photoImg = document.getElementById('createPostPhotoImg');
        const photoRemove = document.getElementById('createPostPhotoRemove');
        const feelingBtn = document.getElementById('createPostFeelingBtn');
        const feelingPicker = document.getElementById('createPostFeelingPicker');
        const feelingBar = document.getElementById('createPostFeelingBar');
        const feelingText = document.getElementById('createPostFeelingText');
        const feelingClear = document.getElementById('createPostFeelingClear');
        const triggerBtn = document.getElementById('composerTriggerBtn');
        const triggerPhoto = document.getElementById('composerTriggerPhoto');
        const triggerFeeling = document.getElementById('composerTriggerFeeling');
        const privacyBtn = document.getElementById('createPostPrivacyBtn');
        const privacyLabelBtn = document.getElementById('createPostPrivacyLabel');
        const privacyPicker = document.getElementById('createPostPrivacyPicker');

        if (!textarea || !submitBtn) return;

        let selectedFile = null;
        let selectedFeeling = null;
        let selectedPrivacy = 'public';
        const PRIVACY_META = {
            public: { label: 'Public', icon: 'fa-globe-americas' },
            friends: { label: 'Friends', icon: 'fa-user-group' },
            private: { label: 'Only me', icon: 'fa-lock' }
        };

        function applyPrivacy(val) {
            selectedPrivacy = PRIVACY_META[val] ? val : 'public';
            const meta = PRIVACY_META[selectedPrivacy];
            const chip = document.getElementById('composerPrivacyChipLabel');
            if (chip) chip.textContent = meta.label;
            if (privacyLabelBtn) {
                privacyLabelBtn.innerHTML = `<i class="fas ${meta.icon}"></i> ${meta.label} <i class="fas fa-caret-down" style="font-size:10px;opacity:.7"></i>`;
            }
            if (privacyBtn) {
                privacyBtn.innerHTML = `<i class="fas ${meta.icon}" style="color:#1877f2"></i>`;
            }
            privacyPicker?.querySelectorAll('.privacy-option').forEach(o => {
                o.classList.toggle('is-active', o.dataset.privacy === selectedPrivacy);
            });
        }

        function resetModal() {
            textarea.value = '';
            textarea.style.height = 'auto';
            selectedFile = null;
            selectedFeeling = null;
            photoInput.value = '';
            photoPreview.style.display = 'none';
            photoImg.src = '';
            feelingBar.style.display = 'none';
            feelingPicker.classList.remove('visible');
            privacyPicker?.classList.remove('visible');
            applyPrivacy('public');
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Post';
        }

        function updateSubmitBtn() {
            const hasText = textarea.value.trim().length > 0;
            submitBtn.disabled = !hasText && !selectedFile;
        }

        function openModal(openWithPhoto) {
            resetModal();
            if (openWithPhoto) {
                photoInput?.click();
            }
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(() => textarea.focus(), 100);
        }

        function closeModal() {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            resetModal();
        }

        // Open modal triggers
        triggerBtn?.addEventListener('click', () => openModal(false));
        triggerPhoto?.addEventListener('click', () => openModal(true));
        triggerFeeling?.addEventListener('click', () => openModal(false));

        // Close
        closeBtn?.addEventListener('click', closeModal);
        overlay?.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && overlay.classList.contains('active')) closeModal();
        });

        // Auto-resize textarea
        textarea.addEventListener('input', () => {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 300) + 'px';
            updateSubmitBtn();
        });

        // Photo
        photoBtn?.addEventListener('click', () => photoInput?.click());
        photoInput?.addEventListener('change', () => {
            const file = photoInput.files?.[0];
            if (!file) return;
            selectedFile = file;
            const reader = new FileReader();
            reader.onload = (e) => {
                photoImg.src = e.target.result;
                photoPreview.style.display = 'flex';
            };
            reader.readAsDataURL(file);
            updateSubmitBtn();
        });
        photoRemove?.addEventListener('click', () => {
            selectedFile = null;
            photoInput.value = '';
            photoPreview.style.display = 'none';
            photoImg.src = '';
            updateSubmitBtn();
        });

        // Feeling picker
        feelingBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            feelingPicker?.classList.toggle('visible');
        });
        feelingPicker?.querySelectorAll('.feeling-option').forEach(btn => {
            btn.addEventListener('click', () => {
                selectedFeeling = btn.dataset.feeling;
                feelingText.textContent = selectedFeeling;
                feelingBar.style.display = 'flex';
                feelingPicker?.classList.remove('visible');
            });
        });
        feelingClear?.addEventListener('click', () => {
            selectedFeeling = null;
            feelingBar.style.display = 'none';
        });

        // Privacy picker
        function togglePrivacyPicker() {
            feelingPicker?.classList.remove('visible');
            privacyPicker?.classList.toggle('visible');
        }
        privacyBtn?.addEventListener('click', (e) => { e.stopPropagation(); togglePrivacyPicker(); });
        privacyLabelBtn?.addEventListener('click', (e) => { e.stopPropagation(); togglePrivacyPicker(); });
        privacyPicker?.querySelectorAll('.privacy-option').forEach(btn => {
            btn.addEventListener('click', () => {
                applyPrivacy(btn.dataset.privacy);
                privacyPicker.classList.remove('visible');
            });
        });
        document.addEventListener('click', (e) => {
            if (privacyPicker && privacyPicker.classList.contains('visible') &&
                !e.target.closest('#createPostPrivacyPicker') &&
                !e.target.closest('#createPostPrivacyBtn') &&
                !e.target.closest('#createPostPrivacyLabel')) {
                privacyPicker.classList.remove('visible');
            }
        });

        // Submit
        submitBtn.addEventListener('click', async () => {
            const content = textarea.value.trim();
            if (!content && !selectedFile) return;

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting...';

            const pageEl = document.querySelector('[data-community-page]') || document.querySelector('[data-profile-page]') || document.querySelector('[data-home-page]');
            const csrfToken = pageEl?.dataset.csrfToken || document.body.dataset.csrfToken;

            const fd = new FormData();
            fd.append('action', 'create_post');
            fd.append('content', content);
            fd.append('privacy', selectedPrivacy);
            fd.append('csrf_token', csrfToken);
            if (selectedFile) fd.append('post_image', selectedFile);
            if (selectedFeeling) fd.append('feeling', selectedFeeling);

            try {
                const r = await fetch('handlers/profile_handlers.php', { method: 'POST', body: fd });
                const d = await r.json();
                if (!d.success) throw new Error(d.message || 'Failed to post');

                const postsEl = document.getElementById('cmFeedPosts');
                const feed = document.querySelector('.community-feed');
                if (postsEl) {
                    resetFeedFilter();
                    const card = renderPostCard(d.post);
                    postsEl.insertBefore(card, postsEl.firstChild);
                    document.getElementById('cmFeedEmptyInitial')?.remove();
                    refreshFeedCount();
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else if (feed) {
                    const card = renderPostCard(d.post);
                    const composerCard = document.querySelector('.community-composer-card');
                    feed.insertBefore(card, composerCard?.nextSibling || feed.firstChild);
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }

                closeModal();
            } catch (err) {
                showStatusModal('error', 'Failed', err.message);
                submitBtn.innerHTML = 'Post';
                submitBtn.disabled = false;
                updateSubmitBtn();
            }
        });
    }

    function initEditModal() {
        const stale = document.querySelector('body > #editPostOverlay');
        if (stale) stale.remove();

        const overlay = document.getElementById('editPostOverlay');
        if (!overlay) return;

        if (overlay.dataset.editInit === '1') return;
        overlay.dataset.editInit = '1';

        document.body.appendChild(overlay);
        const closeBtn = document.getElementById('editPostClose');
        const textarea = document.getElementById('editPostTextarea');
        const submitBtn = document.getElementById('editPostSubmit');
        const photoBtn = document.getElementById('editPostPhotoBtn');
        const photoInput = document.getElementById('editPostPhotoInput');
        const photoChange = document.getElementById('editPostPhotoChange');
        const photoPreview = document.getElementById('editPostPhotoPreview');
        const photoImg = document.getElementById('editPostPhotoImg');
        const photoRemove = document.getElementById('editPostPhotoRemove');
        const feelingBtn = document.getElementById('editPostFeelingBtn');
        const feelingPicker = document.getElementById('editPostFeelingPicker');
        const feelingBar = document.getElementById('editPostFeelingBar');
        const feelingText = document.getElementById('editPostFeelingText');
        const feelingClear = document.getElementById('editPostFeelingClear');

        let selectedFile = null;
        let selectedFeeling = null;

        function resetEditFeeling() {
            selectedFeeling = null;
            feelingBar.style.display = 'none';
            feelingPicker.classList.remove('visible');
        }

        // Close
        function closeEditModal() {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            selectedFile = null;
            window.__editPostId = null;
            window.__editOriginalImage = null;
        }

        closeBtn?.addEventListener('click', closeEditModal);
        overlay?.addEventListener('click', (e) => { if (e.target === overlay) closeEditModal(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && overlay.classList.contains('active')) closeEditModal();
        });

        textarea?.addEventListener('input', () => {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 250) + 'px';
        });

        // Photo
        photoBtn?.addEventListener('click', () => photoInput?.click());
        photoChange?.addEventListener('click', () => photoInput?.click());
        photoInput?.addEventListener('change', () => {
            const file = photoInput.files?.[0];
            if (!file) return;
            selectedFile = file;
            const reader = new FileReader();
            reader.onload = (e) => {
                photoImg.src = e.target.result;
                photoPreview.style.display = 'flex';
            };
            reader.readAsDataURL(file);
        });
        photoRemove?.addEventListener('click', () => {
            selectedFile = 'remove';
            photoPreview.style.display = 'none';
            photoImg.src = '';
            photoInput.value = '';
        });

        // Feeling
        feelingBtn?.addEventListener('click', (e) => { e.stopPropagation(); feelingPicker?.classList.toggle('visible'); });
        feelingPicker?.querySelectorAll('.feeling-option').forEach(btn => {
            btn.addEventListener('click', () => {
                selectedFeeling = btn.dataset.feeling;
                feelingText.textContent = selectedFeeling;
                feelingBar.style.display = 'flex';
                feelingPicker.classList.remove('visible');
            });
        });
        feelingClear?.addEventListener('click', resetEditFeeling);

        // Submit
        submitBtn?.addEventListener('click', async () => {
            const postId = window.__editPostId;
            if (!postId) return;
            const content = textarea.value.trim();

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            const pageEl = document.querySelector('[data-community-page]') || document.querySelector('[data-profile-page]') || document.querySelector('[data-home-page]');
            const csrfToken = pageEl?.dataset.csrfToken || document.body.dataset.csrfToken;

            const fd = new FormData();
            fd.append('action', 'update_post');
            fd.append('post_id', postId);
            fd.append('content', content);
            fd.append('csrf_token', csrfToken);
            if (selectedFile && selectedFile !== 'remove') fd.append('post_image', selectedFile);
            if (selectedFile === 'remove') fd.append('remove_photo', '1');
            if (selectedFeeling) fd.append('feeling', selectedFeeling);

            try {
                const r = await fetch('handlers/profile_handlers.php', { method: 'POST', body: fd });
                const d = await r.json();
                if (!d.success) throw new Error(d.message || 'Failed to update');

                // Update the post card in the feed
                const postCard = document.querySelector(`[data-post-id="${postId}"]`);
                if (postCard) {
                    const contentEl = postCard.querySelector('.community-post-content p');
                    if (contentEl) {
                        contentEl.innerHTML = (content || '').replace(/\n/g, '<br>');
                    }
                    // Update image
                    const imgWrap = postCard.querySelector('.community-post-image');
                    if (d.post?.image_path) {
                        const newSrc = 'assets/posts/' + d.post.image_path;
                        if (imgWrap) {
                            imgWrap.querySelector('img').src = newSrc;
                        } else {
                            const div = document.createElement('div');
                            div.className = 'community-post-image';
                            div.innerHTML = `<img src="${newSrc}" alt="" loading="lazy">`;
                            const contentDiv = postCard.querySelector('.community-post-content');
                            if (contentDiv) contentDiv.after(div);
                            else postCard.insertBefore(div, postCard.querySelector('.community-post-stats'));
                        }
                    } else {
                        if (imgWrap) imgWrap.remove();
                    }
                    // Update feeling
                    const feelingSpan = postCard.querySelector('.community-author-info span[style*="feeling"]');
                    if (d.post?.feeling) {
                        if (feelingSpan) {
                            feelingSpan.innerHTML = 'feeling <strong>' + d.post.feeling + '</strong>';
                        } else {
                            const info = postCard.querySelector('.community-author-info');
                            if (info) {
                                const span = document.createElement('span');
                                span.style.cssText = 'font-size:12px;color:var(--comm-text-secondary);display:block;margin-top:2px';
                                span.innerHTML = 'feeling <strong>' + d.post.feeling + '</strong>';
                                info.appendChild(span);
                            }
                        }
                    } else {
                        if (feelingSpan) feelingSpan.remove();
                    }
                }
                closeEditModal();
            } catch (err) {
                showStatusModal('error', 'Failed', err.message);
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Save';
            }
        });
    }

    function initReportModal() {
        const stale = document.querySelector('body > #reportPostOverlay');
        if (stale) stale.remove();

        const overlay = document.getElementById('reportPostOverlay');
        if (!overlay) return;

        if (overlay.dataset.reportInit === '1') return;
        overlay.dataset.reportInit = '1';

        document.body.appendChild(overlay);
        const closeBtn = document.getElementById('reportPostClose');
        const cancelBtn = document.getElementById('reportCancelBtn');
        const submitBtn = document.getElementById('reportSubmitBtn');
        const reasonInputs = document.querySelectorAll('input[name="report_reason"]');
        const detailInput = document.getElementById('reportDetailInput');

        function closeReportModal() {
            overlay.classList.remove('active');
            document.body.style.overflow = '';
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Submit Report';
        }

        function updateSubmitBtn() {
            const checked = document.querySelector('input[name="report_reason"]:checked');
            submitBtn.disabled = !checked;
        }

        closeBtn?.addEventListener('click', closeReportModal);
        cancelBtn?.addEventListener('click', closeReportModal);
        overlay?.addEventListener('click', (e) => { if (e.target === overlay) closeReportModal(); });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && overlay.classList.contains('active')) closeReportModal();
        });

        reasonInputs.forEach(input => {
            input.addEventListener('change', updateSubmitBtn);
        });

        submitBtn?.addEventListener('click', async () => {
            const postId = window.__reportPostId;
            if (!postId) return;
            const checked = document.querySelector('input[name="report_reason"]:checked');
            if (!checked) return;
            const reason = checked.value;
            const details = detailInput?.value.trim() || '';

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

            const pageEl = document.querySelector('[data-community-page]') || document.querySelector('[data-profile-page]') || document.querySelector('[data-home-page]');
            const csrfToken = pageEl?.dataset.csrfToken || document.body.dataset.csrfToken;

            const fd = new FormData();
            fd.append('action', 'report_post');
            fd.append('post_id', postId);
            fd.append('reason', reason + (details ? ': ' + details : ''));
            fd.append('csrf_token', csrfToken);

            try {
                const r = await fetch('handlers/profile_handlers.php', { method: 'POST', body: fd });
                const d = await r.json();
                if (!d.success) {
                    if (d.already_reported) {
                        closeReportModal();
                        showStatusModal('info', 'Already Reported', 'You have already reported this post. Our team will review it.');
                        return;
                    }
                    throw new Error(d.message || 'Failed to report');
                }
                closeReportModal();
                showStatusModal('success', 'Report Submitted', 'Thank you. Our team will review this post and take appropriate action.');
            } catch (err) {
                showStatusModal('error', 'Failed', err.message);
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Submit Report';
            }
        });
    }

    // ===== COMMUNITY HELPERS (toast / csrf / request badge) =====
    function getCsrfToken() {
        const el = document.querySelector('[data-community-page]') || document.querySelector('[data-profile-page]') || document.querySelector('[data-home-page]');
        return (el && el.dataset.csrfToken) || document.body.dataset.csrfToken || '';
    }

    function cmToast(message, isError) {
        let t = document.getElementById('cmToast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'cmToast';
            t.className = 'cm-toast';
            document.body.appendChild(t);
        }
        t.textContent = message;
        t.classList.toggle('cm-toast--error', !!isError);
        t.classList.add('cm-toast--show');
        clearTimeout(t.__cmToastTimer);
        t.__cmToastTimer = setTimeout(() => t.classList.remove('cm-toast--show'), 2600);
    }

    function refreshFriendRequestBadge() {
        const panel = document.getElementById('cmFriendRequestsPanel');
        if (!panel) return;
        const cards = panel.querySelectorAll('[data-fr-card]');
        const badge = panel.querySelector('.cm-heading-badge');
        if (cards.length > 0) {
            if (badge) badge.textContent = cards.length;
        } else if (badge) {
            badge.remove();
        }
        const list = panel.querySelector('.community-friend-list');
        if (list && cards.length === 0 && !list.querySelector('.cm-panel-empty')) {
            const p = document.createElement('p');
            p.className = 'cm-panel-empty';
            p.textContent = 'No new requests';
            list.appendChild(p);
        }
    }

    // ===== FEED TOOLBAR (filter / sort / search) =====
    const feedState = { filter: 'all', q: '', sort: 'newest' };

    function refreshFeedCount() {
        const el = document.getElementById('cmFeedCount');
        if (!el) return;
        const total = document.querySelectorAll('#cmFeedPosts .community-post-card').length;
        const visible = document.querySelectorAll('#cmFeedPosts .community-post-card:not(.is-filtered-out)').length;
        const n = (total === visible) ? total : visible;
        el.textContent = n + ' ' + (n === 1 ? 'post' : 'posts');
    }

    function applyFeedView() {
        const postsEl = document.getElementById('cmFeedPosts');
        if (!postsEl) return;
        const posts = Array.from(postsEl.querySelectorAll('.community-post-card'));
        const q = feedState.q;
        let visible = 0;

        posts.forEach(card => {
            let ok = true;
            const f = feedState.filter;
            if (f === 'mine') {
                ok = card.dataset.mine === '1';
            } else if (f === 'friends') {
                ok = card.dataset.friend === '1' || card.dataset.mine === '1';
            } else if (f === 'trending') {
                ok = ((Number(card.dataset.likes) || 0) + (Number(card.dataset.comments) || 0) + (Number(card.dataset.shares) || 0)) >= 1;
            }
            if (ok && q) ok = (card.dataset.search || '').toLowerCase().indexOf(q) !== -1;
            card.classList.toggle('is-filtered-out', !ok);
            if (ok) visible++;
        });

        const keyTs = c => Number(c.dataset.ts) || 0;
        const keyLikes = c => Number(c.dataset.likes) || 0;
        const keyComments = c => Number(c.dataset.comments) || 0;
        let sorted;
        if (feedState.sort === 'top') {
            sorted = posts.slice().sort((a, b) => keyLikes(b) - keyLikes(a) || keyTs(b) - keyTs(a));
        } else if (feedState.sort === 'discussed') {
            sorted = posts.slice().sort((a, b) => keyComments(b) - keyComments(a) || keyTs(b) - keyTs(a));
        } else {
            sorted = posts.slice().sort((a, b) => keyTs(b) - keyTs(a));
        }
        sorted.forEach(c => postsEl.appendChild(c));

        const emptyEl = document.getElementById('cmFeedEmpty');
        if (emptyEl) emptyEl.style.display = (posts.length > 0 && visible === 0) ? '' : 'none';
        refreshFeedCount();
    }

    function resetFeedFilter() {
        feedState.filter = 'all';
        feedState.q = '';
        feedState.sort = 'newest';
        const chips = document.getElementById('cmFeedChips');
        chips?.querySelectorAll('.cm-chip').forEach(c => c.classList.toggle('is-active', c.dataset.filter === 'all'));
        const search = document.getElementById('cmFeedSearch');
        if (search) search.value = '';
        const sort = document.getElementById('cmFeedSort');
        if (sort) sort.value = 'newest';
        applyFeedView();
    }

    function initFeedToolbar() {
        const chips = document.getElementById('cmFeedChips');
        if (!chips) return;
        if (chips.dataset.feedInit === '1') return;
        chips.dataset.feedInit = '1';

        chips.addEventListener('click', (e) => {
            const chip = e.target.closest('.cm-chip');
            if (!chip) return;
            chips.querySelectorAll('.cm-chip').forEach(c => c.classList.toggle('is-active', c === chip));
            feedState.filter = chip.dataset.filter || 'all';
            applyFeedView();
        });

        const search = document.getElementById('cmFeedSearch');
        let searchTimer;
        search?.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                feedState.q = search.value.trim().toLowerCase();
                applyFeedView();
            }, 160);
        });

        const sort = document.getElementById('cmFeedSort');
        sort?.addEventListener('change', () => {
            feedState.sort = sort.value || 'newest';
            applyFeedView();
        });

        document.getElementById('cmFeedReset')?.addEventListener('click', resetFeedFilter);

        applyFeedView();
    }

    function renderPostCard(p) {
        const article = document.createElement('article');
        article.className = 'community-post-card';
        article.dataset.postId = p.id;
        article.dataset.userId = parseInt(p.user_id) || '';
        article.dataset.likes = parseInt(p.like_count) || 0;
        article.dataset.comments = parseInt(p.comment_count) || 0;
        article.dataset.shares = parseInt(p.share_count) || 0;
        article.dataset.ts = Math.floor(Date.now() / 1000);

        const ownerName = p.full_name || p.username || 'User';
        const avatar = p.avatar || 'default.png';
        const time = 'Just now';
        const content = (p.content || '').replace(/\n/g, '<br>');
        const authorId = parseInt(p.user_id) || 0;
        const isOwner = authorId && window.__communityViewerId && authorId === parseInt(window.__communityViewerId);
        if (isOwner) article.dataset.mine = '1';
        article.dataset.search = ownerName + ' ' + (p.content || '');

        const PRIVACY_BADGES = { friends: 'fa-user-group', private: 'fa-lock' };
        const priv = p.privacy ? String(p.privacy).toLowerCase() : 'public';
        const privBadge = PRIVACY_BADGES[priv]
            ? `<span class="cm-privacy-badge" title="${priv === 'private' ? 'Only me' : 'Friends'}"><i class="fas ${PRIVACY_BADGES[priv]}"></i></span>`
            : '';

        const profileUrl = `index.php?page=profile&user=${authorId}`;
        const imageHtml = p.image_path
            ? `<div class="community-post-image"><img src="assets/posts/${p.image_path}" alt="" loading="lazy"></div>`
            : '';
        const feelingHtml = p.feeling
            ? `<span class="community-post-feeling">feeling <strong>${escapeHtml(String(p.feeling))}</strong></span>`
            : '';

        const reactionIconsHtml = buildReactionIconsHtml(p.reaction_summary, Number(p.like_count || 0));

        article.innerHTML = `
            <div class="community-post-header">
                <div class="community-post-author">
                    <a href="${profileUrl}" data-no-ajax class="community-author-avatar-link"><img src="assets/avatars/${avatar}" alt="" onerror="this.src='assets/avatars/default.png'"></a>
                    <div class="community-author-info">
                        <strong><a href="${profileUrl}" data-no-ajax>${escapeHtml(ownerName)}</a></strong>
                        <span class="community-post-time">${time}${privBadge}</span>
                        ${feelingHtml}
                    </div>
                </div>
                <div class="post-menu-container">
                    <button type="button" class="post-menu-trigger" title="More options"><i class="fas fa-ellipsis-h"></i></button>
                    <div class="post-dropdown">
                        ${isOwner ? `
                            <button class="post-dropdown-item" data-action="edit" data-post-id="${p.id}"><i class="fas fa-pen"></i> Edit post</button>
                            <button class="post-dropdown-item danger" data-action="delete" data-post-id="${p.id}"><i class="fas fa-trash-alt"></i> Delete post</button>
                        ` : `
                            <button class="post-dropdown-item" data-action="save" data-post-id="${p.id}"><i class="fas fa-bookmark"></i> Save post</button>
                            <div class="post-dropdown-divider"></div>
                            <button class="post-dropdown-item danger" data-action="report" data-post-id="${p.id}"><i class="fas fa-flag"></i> Report post</button>
                        `}
                    </div>
                </div>
            </div>
            ${content ? `<div class="community-post-content"><p>${escapeHtml(content)}</p></div>` : ''}
            ${imageHtml}
            <div class="community-post-stats">
                <div class="community-stat-left" title="View reactions">
                    <div class="community-reaction-icons">${reactionIconsHtml}</div>
                </div>
                <div class="community-stat-right">
                    <span class="community-comment-trigger" data-post-id="${p.id}">${Number(p.comment_count || 0)} comments</span> • <span>${Number(p.share_count || 0)} shares</span>
                </div>
            </div>
            <div class="community-post-actions">
                <div class="community-btn-action-wrap">
                    <button class="community-btn-action" data-action="like" data-reaction="">
                        <i class="far fa-thumbs-up"></i> Like
                    </button>
                    <div class="community-reaction-strip">
                        ${COMMUNITY_REACTIONS.map(r => `<button class="community-reaction-btn" data-reaction="${r.type}" title="${r.label}">${r.emoji}</button>`).join('')}
                    </div>
                </div>
                <div class="community-btn-action-wrap">
                    <button class="community-btn-action community-comment-trigger" data-action="comment" data-post-id="${p.id}"><i class="far fa-comment"></i> Comment</button>
                </div>
                <div class="community-btn-action-wrap">
                    <button class="community-btn-action" data-action="share"><i class="far fa-share-square"></i> Share</button>
                </div>
            </div>
        `;
        return article;
    }

    // Listen for pageChanged to handle post/comment params after AJAX nav
    if (!window.__communityPageChangedAttached) {
        window.__communityPageChangedAttached = true;
        document.addEventListener('pageChanged', (event) => {
            if (event.detail?.page === 'community') {
                const params = new URLSearchParams(window.location.search);
                const postId = params.get('post');
                if (postId) {
                    setTimeout(() => openPostModal(postId), 300);
                }
            }
        });
    }

    // Auto-run if elements exist
    const maybeRun = () => {
        if (document.querySelector('[data-community-page]') || document.querySelector('[data-home-page]') || document.querySelector('[data-profile-page]')) {
            window.initCommunity();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', maybeRun);
    } else {
        maybeRun();
    }
})();

async function submitReaction(postId, reaction) {
    const pageEl = document.querySelector('[data-community-page]') || document.querySelector('[data-home-page]') || document.querySelector('[data-profile-page]');
    if (!pageEl) return;
    const csrfToken = pageEl.dataset.csrfToken || document.body.dataset.csrfToken;
    
    const postCard = document.querySelector(`[data-post-id="${postId}"]`);
    if (!postCard) return;
    const btnWrap = postCard.querySelector('.community-btn-action-wrap');
    const btn = btnWrap ? btnWrap.querySelector('.community-btn-action') : postCard.querySelector('.like-btn');
    const countEl = postCard.querySelector('.community-stat-count') || postCard.querySelector('.like-count');
    
    if (!btn || !countEl) return;

    // Optimistic UI update
    const wasActive = btn.classList.contains('active');
    const prevReaction = btn.dataset.reaction;
    const prevCount = parseInt(countEl.textContent) || 0;
    
    if (wasActive && prevReaction === reaction) {
        btn.classList.remove('active');
        btn.dataset.reaction = '';
        btn.innerHTML = `<i class="far fa-thumbs-up"></i> Like`;
        countEl.textContent = Math.max(0, prevCount - 1);
    } else {
        btn.classList.add('active');
        btn.dataset.reaction = reaction;
        btn.innerHTML = `<span style="margin-right:8px">${getEmoji(reaction)}</span> ${reaction.charAt(0).toUpperCase() + reaction.slice(1)}`;
        if (!wasActive) countEl.textContent = prevCount + 1;
    }

    const fd = new FormData();
    fd.append('action', 'react_post');
    fd.append('post_id', postId);
    fd.append('reaction', reaction);
    fd.append('csrf_token', csrfToken);

    try {
        const response = await fetch('handlers/profile_handlers.php', { method: 'POST', body: fd });
        const data = await response.json();
        if (data.success) {
            if (data.viewer_reaction) {
                btn.classList.add('active');
                btn.dataset.reaction = data.viewer_reaction;
                btn.innerHTML = `<span style="margin-right:8px">${getEmoji(data.viewer_reaction)}</span> ${data.viewer_reaction.charAt(0).toUpperCase() + data.viewer_reaction.slice(1)}`;
            } else {
                btn.classList.remove('active');
                btn.dataset.reaction = '';
                btn.innerHTML = `<i class="far fa-thumbs-up"></i> Like`;
            }
            if (countEl) countEl.textContent = data.count;
            if (data.reaction_summary) updateStatsReactionIcons(postCard, data.reaction_summary, data.count);
        } else {
            // Revert on failure
            countEl.textContent = prevCount;
            if (wasActive) {
                btn.classList.add('active');
                btn.dataset.reaction = prevReaction;
                btn.innerHTML = `<span style="margin-right:8px">${getEmoji(prevReaction)}</span> ${prevReaction.charAt(0).toUpperCase() + prevReaction.slice(1)}`;
            } else {
                btn.classList.remove('active');
                btn.dataset.reaction = '';
                btn.innerHTML = `<i class="far fa-thumbs-up"></i> Like`;
            }
        }
    } catch (err) {}
}

function getEmoji(reaction) {
    const map = {like:'👍', love:'❤️', care:'🥰', haha:'😆', wow:'😮', sad:'😢', angry:'😡'};
    return map[reaction] || '👍';
}

function buildReactionIconsHtml(summary, totalCount) {
    const top = (summary || []).slice(0, 2);
    const emojiMap = {like:'👍', love:'❤️', care:'🥰', haha:'😆', wow:'😮', sad:'😢', angry:'😡'};
    let icons = top.map(r => `<span class="reaction-chip reaction-${r.type}" title="${r.meta?.label || r.type}">${emojiMap[r.type] || '👍'}</span>`).join('');
    if (!icons && totalCount > 0) icons = '<span class="reaction-chip reaction-like" title="Like">👍</span>';
    return `<span class="reaction-stack">${icons}</span>${totalCount > 0 ? `<span class="like-count">${totalCount}</span>` : ''}`;
}

function updateStatsReactionIcons(postCard, summary, count) {
    const wrap = postCard.querySelector('.community-reaction-icons');
    if (wrap) wrap.innerHTML = buildReactionIconsHtml(summary, count);
    const countEl = postCard.querySelector('.community-stat-count');
    if (countEl) countEl.textContent = count;
}

async function sharePost(postId, btn) {
    const pageEl = document.querySelector('[data-community-page]') || document.querySelector('[data-home-page]') || document.querySelector('[data-profile-page]');
    const csrfToken = pageEl?.dataset.csrfToken || document.body.dataset.csrfToken;

    const fd = new FormData();
    fd.append('action', 'share_post');
    fd.append('post_id', postId);
    fd.append('csrf_token', csrfToken);

    try {
        const response = await fetch('handlers/profile_handlers.php', { method: 'POST', body: fd });
        const data = await response.json();
        if (data.success) {
            const shareUrl = `${window.location.origin}${window.location.pathname}?page=community&post=${postId}`;
            if (navigator.share) {
                navigator.share({ title: 'DreamBD Post', url: shareUrl }).catch(() => {});
            } else if (navigator.clipboard?.writeText) {
                navigator.clipboard.writeText(shareUrl).catch(() => {});
            }
            const postCard = btn.closest('.community-post-card');
            if (postCard) {
                const shareCountEl = postCard.querySelector('.community-stat-right span:last-child');
                if (shareCountEl) shareCountEl.textContent = data.share_count + ' shares';
            }
        }
    } catch (err) {}
}

async function loadCommunityContacts() {
    const contactList = document.getElementById('community-contacts-list');
    if (!contactList) return;

    try {
        const response = await fetch('handlers/profile_handlers.php?action=get_contacts');
        const res = await response.json();
        if (res.success && res.contacts.length > 0) {
            contactList.innerHTML = res.contacts.map(u => `
                <a href="index.php?page=profile&user=${u.id}" class="community-left-user" style="text-decoration:none">
                    <div style="position:relative">
                        <img src="assets/avatars/${u.avatar || 'default.png'}" alt="" onerror="this.src='assets/avatars/default.png'">
                        ${u.is_online ? '<span style="position:absolute;bottom:0;right:0;width:10px;height:10px;background:#31a24c;border-radius:50%;border:2px solid #fff"></span>' : ''}
                    </div>
                    <strong>${u.full_name || u.username}</strong>
                </a>
            `).join('');
        } else {
            contactList.innerHTML = '<div style="padding:10px; color:var(--comm-text-secondary); font-size:14px">No contacts to show</div>';
        }
    } catch (err) {}
}

let _commentOffset = 10;
let _loadingMoreComments = false;

function setupCommentInfiniteScroll(postId) {
    const body = document.querySelector('.post-detail-body');
    if (!body) return;
    
    body.addEventListener('scroll', async function handler() {
        if (_loadingMoreComments) return;
        if (this.scrollTop + this.clientHeight >= this.scrollHeight - 400) {
            _loadingMoreComments = true;
            try {
                const r = await fetch(`handlers/profile_handlers.php?action=get_more_comments&post_id=${postId}&offset=${_commentOffset}`);
                const data = await r.json();
                if (data.success && data.comments.length > 0) {
                    const list = document.getElementById('commentsList');
                    if (list) {
                        data.comments.forEach(c => {
                            list.insertAdjacentHTML('beforeend', renderCommentHtml(c, { canInteract: true, depth: 0 }));
                        });
                        _commentOffset += data.comments.length;
                    }
                }
            } catch (e) {}
            _loadingMoreComments = false;
        }
    });
}

function openPostModal(postId) {
    
    const existing = document.getElementById('postDetailOverlay');
    if (existing) existing.remove();
    _commentOffset = 10;
    _loadingMoreComments = false;

    // Check if we need to scroll to a specific comment
    const params = new URLSearchParams(window.location.search);
    const targetCommentId = params.get('comment');

    const overlay = document.createElement('div');
    overlay.className = 'gp-modal-overlay active';
    overlay.id = 'postDetailOverlay';
    const isLoggedIn = document.body.dataset.loggedIn === '1';
    const closePostModal = () => {
        overlay.remove();
        document.body.style.overflow = '';
    };
    overlay.innerHTML = `
        <div class="gp-modal-box gp-modal-box--premium post-detail-modal">
            <div class="gp-modal-header--premium post-detail-header">
                <h2>Post Details</h2>
                <button type="button" class="gp-modal-close-btn post-detail-close" aria-label="Close post details">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="gp-modal-body--premium post-detail-body">
                <div id="postDetailContent">
                    <div class="post-detail-loading">
                        <i class="fas fa-circle-notch fa-spin"></i>
                        <div>Loading post...</div>
                    </div>
                </div>
            </div>
            ${isLoggedIn ? `
                <div class="modal-comment-box">
                    <img src="assets/avatars/default.png" id="modal-user-avatar" alt="" class="modal-comment-avatar">
                    <div class="modal-comment-main">
                        <div class="modal-reply-context hidden" id="modalReplyContext">
                            <span id="modalReplyText"></span>
                            <button type="button" id="modalReplyCancel" aria-label="Cancel reply"><i class="fas fa-times"></i></button>
                        </div>
                        <div class="modal-comment-input-wrap">
                            <input type="text" class="modal-comment-input" placeholder="Write a comment..." id="modalCommentInput" data-replying-to="0">
                            <button class="modal-comment-send" id="modalCommentSend" disabled><i class="fas fa-paper-plane"></i></button>
                        </div>
                    </div>
                </div>
            ` : `
                <div class="modal-comment-box modal-comment-box--guest">
                    <span>Log in to comment or reply.</span>
                    <a href="index.php?page=login">Sign in</a>
                </div>
            `}
        </div>
    `;
    
    const modalRoot = document.getElementById('modalRoot') || document.body;
    modalRoot.appendChild(overlay);
    
    document.body.style.overflow = 'hidden';
    overlay.querySelector('.post-detail-close')?.addEventListener('click', closePostModal);

    const currentAvatar = document.querySelector('.community-composer-top img, .home-feed-composer-avatar img')?.src;
    if (currentAvatar && document.getElementById('modal-user-avatar')) document.getElementById('modal-user-avatar').src = currentAvatar;

    overlay.addEventListener('click', (e) => { if (e.target === overlay) closePostModal(); });
    
    const commentInput = document.getElementById('modalCommentInput');
    const sendBtn = document.getElementById('modalCommentSend');
    const fetchPost = (canInteract) => {
        fetch(`handlers/profile_handlers.php?action=get_post_details&post_id=${postId}`)
            .then(r => { if (!r.ok) throw new Error('Server returned ' + r.status); return r.json(); })
            .then(res => {
                if (!res.success) {
                    document.getElementById('postDetailContent').innerHTML = `<div class="post-detail-empty">${escapeHtml(res.message || 'Post not found.')}</div>`;
                    return;
                }
                renderModalContent(res.post, { canInteract });
                setupCommentInfiniteScroll(postId);
                if (targetCommentId) setTimeout(() => scrollToComment(targetCommentId), 200);
            })
            .catch(err => {
                console.error('openPostModal error:', err);
                const el = document.getElementById('postDetailContent');
                if (el) el.innerHTML = '<div class="post-detail-empty">Error loading post.</div>';
            });
    };
    if (!commentInput || !sendBtn) {
        fetchPost(false);
        return;
    }
    
    commentInput.addEventListener('input', () => {
        sendBtn.disabled = !commentInput.value.trim();
        if (commentInput.dataset.replyingTo !== "0" && !commentInput.value.startsWith('@')) {
            clearReplyTarget();
        }
    });
    document.getElementById('modalReplyCancel')?.addEventListener('click', clearReplyTarget);
    
    const handleSubmission = () => {
        if (sendBtn.disabled) return;
        const parentId = parseInt(commentInput.dataset.replyingTo || 0);
        submitComment(postId, parentId);
        clearReplyTarget();
    };

    sendBtn.addEventListener('click', handleSubmission);
    commentInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleSubmission();
        }
    });

    fetchPost(true);
}

function scrollToComment(commentId) {
    const el = document.querySelector(`.community-comment[data-comment-id="${commentId}"]`);
    if (!el) return;
    const body = el.closest('.gp-modal-body--premium');
    if (body) {
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
    el.style.transition = 'background 0.5s ease';
                    el.style.background = 'var(--comm-primary-soft)';
    setTimeout(() => { el.style.background = ''; }, 2000);
}

function renderModalContent(p, options = {}) {
    const canInteract = options.canInteract !== false;
    const content = document.getElementById('postDetailContent');
    const ownerName = p.full_name || p.username;
    document.querySelector('#postDetailOverlay h2').textContent = ownerName + "'s Post";
    
    content.innerHTML = `
        <div class="community-post-header">
            <div class="community-post-author">
                <img src="assets/avatars/${escapeAttr(p.avatar || 'default.png')}" onerror="this.src='assets/avatars/default.png'">
                <div class="community-author-info">
                    <strong>${escapeHtml(ownerName)}</strong>
                    <span class="community-post-time">${escapeHtml(p.created_at_formatted || 'Just now')}</span>
                </div>
            </div>
        </div>
        <div class="community-post-content post-detail-copy">
            <p>${formatModalText(p.content || '')}</p>
        </div>
        ${p.image_path ? `
            <div class="community-post-image post-detail-image">
                <img src="assets/posts/${escapeAttr(p.image_path)}" alt="">
            </div>
        ` : ''}
        
        <div class="community-post-stats post-detail-stats">
            <div class="community-stat-left">
                <div class="community-reaction-icons">${buildReactionIconsHtml(p.reaction_summary, Number(p.like_count || 0))}</div>
            </div>
            <div class="community-stat-right">
                <span id="modal-comment-count">${Number(p.comment_count || (p.comments || []).length || 0)} comments</span>
            </div>
        </div>

        <div class="post-detail-comments-wrap">
            <div id="commentsList" class="post-detail-comments-list">
                ${(p.comments || []).map(c => renderCommentHtml(c, { canInteract, depth: 0 })).join('')}
                ${(p.comments || []).length === 0 ? '<div id="noCommentsMsg" class="post-detail-empty">No comments yet.</div>' : ''}
            </div>
        </div>
    `;
}

function renderCommentHtml(c, options = {}) {
    const canInteract = options.canInteract !== false;
    const depth = Math.min(Number(options.depth || 0), 4);
    const isLiked = c.viewer_reaction === 'like';
    const replies = c.replies || [];
    const showAll = replies.length <= 2;
    const visibleReplies = showAll ? replies : replies.slice(0, 2);
    const hiddenReplies = showAll ? [] : replies.slice(2);
    return `
        <div class="community-comment post-detail-comment" data-comment-id="${Number(c.id)}" data-depth="${depth}">
            <img src="assets/avatars/${escapeAttr(c.avatar || 'default.png')}" class="post-detail-comment-avatar" alt="" onerror="this.src='assets/avatars/default.png'">
            <div class="community-comment-bubble-wrap post-detail-comment-main">
                <div class="community-comment-bubble">
                    <div class="post-detail-comment-name">${escapeHtml(c.full_name || c.username || 'User')}</div>
                    <div class="post-detail-comment-text">${formatModalText(c.comment_text || '')}</div>
                </div>
                <div class="community-comment-actions">
                    ${canInteract ? `<span class="comment-like-btn ${isLiked ? 'active' : ''}">Like</span>` : ''}
                    ${canInteract ? `<span class="comment-reply-trigger" data-username="${escapeAttr(c.username || c.full_name || 'user')}">Reply</span>` : ''}
                    <span class="post-detail-comment-time">${escapeHtml(c.created_at_formatted || 'Just now')}</span>
                    <span class="comment-reaction-count-wrap" style="display: ${Number(c.reaction_count || 0) > 0 ? 'flex' : 'none'};">
                        <i class="fas fa-thumbs-up"></i> 
                        <span class="comment-reaction-count">${c.reaction_count}</span>
                    </span>
                </div>
                <div class="comment-replies-list" id="replies-${Number(c.id)}">
                    ${visibleReplies.map(r => renderCommentHtml(r, { canInteract, depth: depth + 1 })).join('')}
                    ${hiddenReplies.length > 0 ? `<div class="reply-hidden-wrap" style="display:none">${hiddenReplies.map(r => renderCommentHtml(r, { canInteract, depth: depth + 1 })).join('')}</div>` : ''}
                    ${!showAll ? `<div class="view-all-replies-wrap"><button type="button" class="view-all-replies-btn" data-comment-id="${Number(c.id)}">View all ${replies.length} replies</button></div>` : ''}
                </div>
            </div>
        </div>
    `;
}

async function submitComment(postId, parentId = 0) {
    const input = document.getElementById('modalCommentInput');
    const sendBtn = document.getElementById('modalCommentSend');
    const text = input.value.trim();
    if (!text) return;

    const pageEl = document.querySelector('[data-community-page]') || document.querySelector('[data-home-page]') || document.querySelector('[data-profile-page]');
    const csrfToken = pageEl ? (pageEl.dataset.csrfToken || document.body.dataset.csrfToken) : document.body.dataset.csrfToken;
    
    // Disable early
    sendBtn.disabled = true;

    const fd = new FormData();
    fd.append('action', 'add_comment');
    fd.append('post_id', postId);
    fd.append('comment', text);
    if (parentId > 0) fd.append('parent_comment_id', parentId);
    fd.append('csrf_token', csrfToken);

    input.value = '';
    clearReplyTarget();

    try {
        const response = await fetch('handlers/profile_handlers.php', { method: 'POST', body: fd });
        const data = await response.json();
        if (data.success) {
            const comment = data.comment;
            const effectiveParent = Number(comment.parent_comment_id || 0);
            let depth = 0;
            if (effectiveParent > 0) {
                const parentEl = document.querySelector(`.community-comment[data-comment-id="${effectiveParent}"]`);
                depth = parentEl ? Math.min(Number(parentEl.dataset.depth || 0) + 1, 4) : 1;
            }
            const html = renderCommentHtml(comment, { canInteract: true, depth });
            
            const list = effectiveParent > 0 ? document.getElementById(`replies-${effectiveParent}`) : document.getElementById('commentsList');
            if (list) {
                const noMsg = document.getElementById('noCommentsMsg');
                if (noMsg) noMsg.remove();
                list.insertAdjacentHTML('beforeend', html);
                list.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            
            // Update counts in modal
            const countEl = document.getElementById('modal-comment-count');
            if (countEl) {
                const currentCount = parseInt(countEl.textContent) || 0;
                countEl.textContent = (currentCount + 1) + ' comments';
            }
            
            // Update counts in feed
            const feedPost = document.querySelector(`[data-post-id="${postId}"]`);
            if (feedPost) {
                const feedCountEl = feedPost.querySelector('.community-comment-trigger');
                if (feedCountEl) {
                    const currentFeedCount = parseInt(feedCountEl.textContent) || 0;
                    feedCountEl.textContent = (currentFeedCount + 1) + ' comments';
                }
            }
        } else {
            alert(data.message || 'Failed to add comment');
            input.value = text; // Restore text
            sendBtn.disabled = false;
        }
    } catch (err) { 
        
        input.value = text; // Restore text
        sendBtn.disabled = false;
    }
}

async function reactComment(commentId) {
    const pageEl = document.querySelector('[data-community-page]') || document.querySelector('[data-home-page]') || document.querySelector('[data-profile-page]');
    const csrfToken = pageEl ? (pageEl.dataset.csrfToken || document.body.dataset.csrfToken) : document.body.dataset.csrfToken;
    const fd = new FormData();
    fd.append('action', 'toggle_comment_reaction');
    fd.append('comment_id', commentId);
    fd.append('reaction', 'like');
    fd.append('csrf_token', csrfToken);

    try {
        const response = await fetch('handlers/profile_handlers.php', { method: 'POST', body: fd });
        const data = await response.json();
        if (data.success) {
            const commentEl = document.querySelector(`.community-comment[data-comment-id="${commentId}"]`);
            if (!commentEl) return;

            const likeBtn = commentEl.querySelector('.comment-like-btn');
            const countWrap = commentEl.querySelector('.comment-reaction-count-wrap');
            const countEl = commentEl.querySelector('.comment-reaction-count');

            if (data.viewer_reaction) {
                likeBtn.classList.add('active');
                likeBtn.style.color = '#1877f2';
                likeBtn.style.fontWeight = '700';
            } else {
                likeBtn.classList.remove('active');
                likeBtn.style.color = '';
                likeBtn.style.fontWeight = '';
            }

            if (data.reaction_count > 0) {
                countWrap.style.display = 'flex';
                countEl.textContent = data.reaction_count;
            } else {
                countWrap.style.display = 'none';
            }
        }
    } catch (err) {}
}

function focusReply(commentId, username) {
    const input = document.getElementById('modalCommentInput');
    if (!input) return;
    input.focus();
    input.value = `@${username} `;
    input.placeholder = `Replying to @${username}...`;
    input.dataset.replyingTo = commentId;
    const context = document.getElementById('modalReplyContext');
    const text = document.getElementById('modalReplyText');
    if (context && text) {
        text.textContent = `Replying to ${username}`;
        context.classList.remove('hidden');
    }
    input.dispatchEvent(new Event('input'));
}

function clearReplyTarget() {
    const input = document.getElementById('modalCommentInput');
    if (!input) return;
    input.dataset.replyingTo = "0";
    input.placeholder = "Write a comment...";
    const context = document.getElementById('modalReplyContext');
    const text = document.getElementById('modalReplyText');
    if (context) context.classList.add('hidden');
    if (text) text.textContent = '';
}

function formatModalText(text) {
    return escapeHtml(text).replace(/\n/g, '<br>');
}

function escapeAttr(text) {
    return escapeHtml(String(text)).replace(/`/g, '&#096;');
}

// === STATUS MODAL HELPER ===
function showStatusModal(type, title, message) {
    const overlay = document.getElementById('communityStatusOverlay');
    const icon = document.getElementById('communityStatusIcon');
    const titleEl = document.getElementById('communityStatusTitle');
    const subEl = document.getElementById('communityStatusSub');

    icon.className = type === 'success' ? 'gp-success-icon' : type === 'error' ? 'gp-fail-icon' : 'gp-info-icon';
    icon.innerHTML = type === 'success' ? '<i class="fas fa-check"></i>' : type === 'error' ? '<i class="fas fa-times"></i>' : '<i class="fas fa-info"></i>';
    titleEl.textContent = title;
    subEl.textContent = message;
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    const dismiss = () => {
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    };

    const dismissBtn = document.getElementById('communityStatusDismiss');
    const newBtn = dismissBtn.cloneNode(true);
    dismissBtn.parentNode.replaceChild(newBtn, dismissBtn);
    newBtn.addEventListener('click', dismiss);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) dismiss(); });
}

// === DELETE CONFIRMATION ===
function showDeleteConfirmation(postId) {
    const overlay = document.getElementById('communityDeleteOverlay');
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    const closeDelete = () => {
        overlay.style.display = 'none';
        document.body.style.overflow = '';
    };

    const cancelBtn = document.getElementById('communityDeleteCancel');
    const confirmBtn = document.getElementById('communityDeleteConfirm');

    const newCancel = cancelBtn.cloneNode(true);
    cancelBtn.parentNode.replaceChild(newCancel, cancelBtn);
    newCancel.addEventListener('click', closeDelete);

    const newConfirm = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirm, confirmBtn);
    newConfirm.addEventListener('click', async () => {
        closeDelete();
            const pageEl = document.querySelector('[data-community-page]') || document.querySelector('[data-profile-page]') || document.querySelector('[data-home-page]');
            const csrfToken = pageEl?.dataset.csrfToken || document.body.dataset.csrfToken;

        const fd = new FormData();
        fd.append('action', 'delete_post');
        fd.append('post_id', postId);
        fd.append('csrf_token', csrfToken);

        try {
            const r = await fetch('handlers/profile_handlers.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (!d.success) throw new Error(d.message || 'Failed to delete');
            const postCard = document.querySelector(`[data-post-id="${postId}"]`);
            if (postCard) postCard.remove();
            showStatusModal('success', 'Deleted!', 'The post has been deleted successfully.');
        } catch (err) {
            showStatusModal('error', 'Failed', err.message);
        }
    });

    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeDelete(); });
}

// === POST CARD MENU HANDLERS ===

async function handleEditPost(postId) {
    const postCard = document.querySelector(`[data-post-id="${postId}"]`);
    if (!postCard) return;
    const contentEl = postCard.querySelector('.community-post-content p');
    const currentContent = contentEl ? contentEl.innerText : '';
    const existingImage = postCard.querySelector('.community-post-image img')?.src || '';
    const feelingEl = postCard.querySelector('.community-author-info span[style*="feeling"] strong');
    const currentFeeling = feelingEl ? feelingEl.textContent : '';

    window.__editPostId = postId;
    window.__editOriginalImage = existingImage;

    const editTextarea = document.getElementById('editPostTextarea');
    const editFeelingBar = document.getElementById('editPostFeelingBar');
    const editFeelingText = document.getElementById('editPostFeelingText');
    const editPreview = document.getElementById('editPostPhotoPreview');
    const editPreviewImg = document.getElementById('editPostPhotoImg');

    editTextarea.value = currentContent;
    editTextarea.style.height = 'auto';
    editTextarea.style.height = Math.min(editTextarea.scrollHeight, 250) + 'px';

    if (currentFeeling) {
        editFeelingText.textContent = currentFeeling;
        editFeelingBar.style.display = 'flex';
    } else {
        editFeelingBar.style.display = 'none';
    }

    if (existingImage) {
        editPreviewImg.src = existingImage;
        editPreview.style.display = 'flex';
    } else {
        editPreview.style.display = 'none';
        editPreviewImg.src = '';
    }

    document.getElementById('editPostOverlay').classList.add('active');
    document.body.style.overflow = 'hidden';
    setTimeout(() => editTextarea.focus(), 100);
}

async function handleDeletePost(postId) {
    showDeleteConfirmation(postId);
}

async function handleSavePost(postId, menuItem) {
            const pageEl = document.querySelector('[data-community-page]') || document.querySelector('[data-profile-page]') || document.querySelector('[data-home-page]');
            const csrfToken = pageEl?.dataset.csrfToken || document.body.dataset.csrfToken;

    const fd = new FormData();
    fd.append('action', 'save_post');
    fd.append('post_id', postId);
    fd.append('csrf_token', csrfToken);

    try {
        const r = await fetch('handlers/profile_handlers.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (!d.success) throw new Error(d.message || 'Failed');
        if (d.saved) {
            menuItem.innerHTML = '<i class="fas fa-bookmark" style="color:#1877f2"></i> Saved ✓';
            showStatusModal('success', 'Saved!', 'Post has been saved to your collection.');
        } else {
            menuItem.innerHTML = '<i class="fas fa-bookmark"></i> Save post';
            showStatusModal('info', 'Unsaved', 'Post removed from your saved collection.');
        }
    } catch (err) {
        showStatusModal('error', 'Failed', err.message);
    }
}

async function handleReportPost(postId) {
    window.__reportPostId = postId;
    const reportOverlay = document.getElementById('reportPostOverlay');
    document.querySelectorAll('input[name="report_reason"]').forEach(r => r.checked = false);
    document.getElementById('reportDetailInput').value = '';
    const submitBtn = document.getElementById('reportSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = 'Submit Report';
    reportOverlay.classList.add('active');
    document.body.style.overflow = 'hidden';
}
