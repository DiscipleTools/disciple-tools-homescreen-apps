const { createApp, ref, computed, onMounted, nextTick, watch } = Vue;

const MyContactsApp = createApp({
    setup() {
        // Reactive state
        const contacts = ref([]);
        const filteredContacts = ref([]);
        const selectedContactId = ref(null);
        const selectedContact = ref(null);
        const searchTerm = ref('');
        const loading = ref(true);
        const detailsLoading = ref(false);
        const contactsCount = computed(() => filteredContacts.value.length);

        // Comment state
        const commentText = ref('');
        const commentSubmitting = ref(false);

        // Mention state
        const mentionUsers = ref([]);
        const mentionActiveIndex = ref(0);
        const mentionStartPos = ref(-1);
        const showMentionDropdown = ref(false);
        let mentionSearchTimeout = null;

        // Mobile state
        const isMobileDetailsVisible = ref(false);
        const mobileActiveTab = ref('details');

        // Load contacts on mount
        onMounted(() => {
            loadContacts();
            setupGlobalEventListeners();
        });

        // Watch search term to filter contacts
        watch(searchTerm, (term) => {
            if (!term) {
                filteredContacts.value = contacts.value;
            } else {
                const lowerTerm = term.toLowerCase();
                filteredContacts.value = contacts.value.filter(c =>
                    c.name.toLowerCase().includes(lowerTerm) ||
                    (c.overall_status && c.overall_status.toLowerCase().includes(lowerTerm)) ||
                    (c.seeker_path && c.seeker_path.toLowerCase().includes(lowerTerm))
                );
            }
        });

        // API helper
        async function apiRequest(endpoint, data = {}) {
            const response = await fetch(
                `${myContactsApp.root}${myContactsApp.parts.root}/v1/${myContactsApp.parts.type}/${endpoint}`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': myContactsApp.nonce
                    },
                    body: JSON.stringify({
                        parts: myContactsApp.parts,
                        ...data
                    })
                }
            );
            return response.json();
        }

        // Load contacts
        async function loadContacts() {
            loading.value = true;
            try {
                const data = await apiRequest('contacts');
                contacts.value = data.contacts || [];
                filteredContacts.value = contacts.value;
            } catch (error) {
                console.error('Error loading contacts:', error);
                contacts.value = [];
                filteredContacts.value = [];
            } finally {
                loading.value = false;
            }
        }

        // Select contact
        async function selectContact(contactId) {
            selectedContactId.value = contactId;
            detailsLoading.value = true;

            if (isMobile()) {
                isMobileDetailsVisible.value = true;
            }

            try {
                const contact = await apiRequest('contact', { contact_id: contactId });

                if (contact.code) {
                    selectedContact.value = { error: contact.message || 'Unknown error' };
                    return;
                }

                // Group activity items
                contact.groupedActivity = groupActivityItems(contact.activity || []);
                selectedContact.value = contact;

                await nextTick();
                initMentionListeners();
            } catch (error) {
                console.error('Error loading contact details:', error);
                selectedContact.value = { error: 'Error loading details' };
            } finally {
                detailsLoading.value = false;
            }
        }

        // Group activity items
        function groupActivityItems(items) {
            const grouped = [];
            let currentGroup = null;

            items.forEach(item => {
                if (item.type === 'comment') {
                    if (currentGroup) {
                        grouped.push({ type: 'activity-group', items: currentGroup });
                        currentGroup = null;
                    }
                    grouped.push(item);
                } else {
                    if (!currentGroup) currentGroup = [];
                    currentGroup.push(item);
                }
            });

            if (currentGroup) {
                grouped.push({ type: 'activity-group', items: currentGroup });
            }

            return grouped;
        }

        // Toggle activity group
        function toggleActivityGroup(index) {
            const group = document.getElementById(`activity-group-${index}`);
            if (group) {
                group.classList.toggle('expanded');
            }
        }

        // Submit comment
        async function submitComment() {
            const comment = commentText.value.trim();
            if (!comment || !selectedContactId.value) return;

            commentSubmitting.value = true;

            try {
                const result = await apiRequest('comment', {
                    contact_id: selectedContactId.value,
                    comment: comment
                });

                if (result.success || result.comment_id) {
                    // Add new comment to grouped activity
                    const newComment = {
                        type: 'comment',
                        id: result.comment_id,
                        content: comment,
                        author: result.author || 'You',
                        timestamp: Math.floor(Date.now() / 1000)
                    };

                    if (selectedContact.value && selectedContact.value.groupedActivity) {
                        selectedContact.value.groupedActivity.unshift(newComment);
                    }

                    commentText.value = '';
                    showSuccessToast('Comment added successfully!');
                } else {
                    alert('Failed to add comment: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error posting comment:', error);
                alert('Error posting comment');
            } finally {
                commentSubmitting.value = false;
            }
        }

        // Mention functionality
        function initMentionListeners() {
            const textarea = document.getElementById('comment-textarea');
            if (!textarea || textarea.hasAttribute('data-mention-init')) return;
            textarea.setAttribute('data-mention-init', 'true');

            textarea.addEventListener('input', handleMentionInput);
            textarea.addEventListener('keydown', handleMentionKeydown);
        }

        function handleMentionInput(e) {
            const text = e.target.value;
            const cursorPos = e.target.selectionStart;
            const textBeforeCursor = text.substring(0, cursorPos);
            const lastAtIndex = textBeforeCursor.lastIndexOf('@');

            if (lastAtIndex !== -1) {
                const textAfterAt = textBeforeCursor.substring(lastAtIndex + 1);

                if (!textAfterAt.includes(' ') && !textAfterAt.includes('\n')) {
                    mentionStartPos.value = lastAtIndex;

                    clearTimeout(mentionSearchTimeout);
                    mentionSearchTimeout = setTimeout(() => {
                        searchMentionUsers(textAfterAt);
                    }, 200);
                    return;
                }
            }

            hideMentionDropdown();
        }

        function handleMentionKeydown(e) {
            if (!showMentionDropdown.value) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                mentionActiveIndex.value = Math.min(mentionActiveIndex.value + 1, mentionUsers.value.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                mentionActiveIndex.value = Math.max(mentionActiveIndex.value - 1, 0);
            } else if (e.key === 'Enter' && mentionUsers.value.length > 0) {
                e.preventDefault();
                selectMention(mentionUsers.value[mentionActiveIndex.value]);
            } else if (e.key === 'Escape') {
                hideMentionDropdown();
            }
        }

        async function searchMentionUsers(search) {
            if (search.length < 1) {
                hideMentionDropdown();
                return;
            }

            try {
                const data = await apiRequest('users-mention', { search });
                mentionUsers.value = data.users || [];
                mentionActiveIndex.value = 0;

                if (mentionUsers.value.length > 0) {
                    showMentionDropdown.value = true;
                } else {
                    hideMentionDropdown();
                }
            } catch (error) {
                console.error('Error searching users:', error);
                hideMentionDropdown();
            }
        }

        function selectMention(user) {
            const textarea = document.getElementById('comment-textarea');
            const text = textarea.value;
            const cursorPos = textarea.selectionStart;

            const beforeMention = text.substring(0, mentionStartPos.value);
            const afterCursor = text.substring(cursorPos);

            const mentionTextStr = `@[${user.name}](${user.ID}) `;
            commentText.value = beforeMention + mentionTextStr + afterCursor;

            nextTick(() => {
                const newCursorPos = beforeMention.length + mentionTextStr.length;
                textarea.setSelectionRange(newCursorPos, newCursorPos);
                textarea.focus();
            });

            hideMentionDropdown();
        }

        function hideMentionDropdown() {
            showMentionDropdown.value = false;
            mentionUsers.value = [];
            mentionStartPos.value = -1;
        }

        // Field editing
        function toggleEditMode(fieldKey) {
            const section = document.querySelector(`.detail-section[data-field-key="${fieldKey}"]`);
            if (!section) return;

            const isEditing = section.classList.contains('editing');

            document.querySelectorAll('.detail-section.editing').forEach(el => {
                if (el !== section) {
                    el.classList.remove('editing');
                }
            });

            if (isEditing) {
                section.classList.remove('editing');
            } else {
                section.classList.add('editing');
                initFieldChangeListener(section);
            }
        }

        function initFieldChangeListener(section) {
            const editMode = section.querySelector('.edit-mode');
            const component = editMode.querySelector('dt-text, dt-textarea, dt-number, dt-toggle, dt-date, dt-single-select, dt-multi-select, dt-multi-text, dt-tags, dt-connection, dt-location');

            if (!component || component.hasAttribute('data-listener-added')) return;

            component.setAttribute('data-listener-added', 'true');

            const fieldType = section.dataset.fieldType;

            component.addEventListener('change', async (e) => {
                const fieldKey = section.dataset.fieldKey;
                const contactId = section.dataset.contactId;
                const rawValue = e.detail?.newValue ?? e.detail?.value ?? component.value;

                let newValue = rawValue;
                if (window.DtWebComponents?.ComponentService?.convertValue) {
                    newValue = window.DtWebComponents.ComponentService.convertValue(
                        component.tagName,
                        rawValue
                    );
                }

                await saveFieldValue(contactId, fieldKey, fieldType, newValue, section);
            });
        }

        const multiValueFieldTypes = ['multi_select', 'connection', 'tags', 'location', 'location_meta', 'communication_channel'];

        async function saveFieldValue(contactId, fieldKey, fieldType, value, section) {
            section.classList.add('saving');

            try {
                const result = await apiRequest('update-field', {
                    contact_id: parseInt(contactId),
                    field_key: fieldKey,
                    field_value: value
                });

                if (result.success) {
                    const viewMode = section.querySelector('.view-mode');
                    const displayValue = result.value || '-';
                    viewMode.textContent = displayValue;
                    viewMode.classList.toggle('empty-value', !result.value);

                    if (!multiValueFieldTypes.includes(fieldType)) {
                        section.classList.remove('editing');
                    }
                    showSuccessToast('Field updated');
                } else {
                    alert(result.message || 'Failed to update field');
                }
            } catch (error) {
                console.error('Error saving field:', error);
                alert('Error saving field');
            } finally {
                section.classList.remove('saving');
            }
        }

        // Global event listeners
        function setupGlobalEventListeners() {
            // Handle dt:get-data events for typeahead
            document.addEventListener('dt:get-data', async function(e) {
                if (!e.detail) return;

                const { field, query, onSuccess, onError, postType } = e.detail;

                try {
                    const data = await apiRequest('field-options', {
                        field: field,
                        query: query || '',
                        post_type: postType || 'contacts'
                    });

                    if (data.success && data.options) {
                        if (onSuccess && typeof onSuccess === 'function') {
                            onSuccess(data.options);
                        }
                    } else {
                        if (onError && typeof onError === 'function') {
                            onError(new Error(data.message || 'Failed to fetch options'));
                        }
                    }
                } catch (err) {
                    console.error('Error fetching field options:', err);
                    if (onError && typeof onError === 'function') {
                        onError(err);
                    }
                }
            });

            // Close edit mode when clicking outside
            document.addEventListener('click', function(e) {
                const editingSection = document.querySelector('.detail-section.editing');
                if (!editingSection) return;

                if (editingSection.contains(e.target)) return;
                if (e.target.closest('.option-list, ul[class*="option"], li[tabindex]')) return;

                editingSection.classList.remove('editing');
            });
        }

        // Mobile helpers
        function isMobile() {
            return window.innerWidth <= 768;
        }

        function hideMobileDetails() {
            isMobileDetailsVisible.value = false;
        }

        // Utility functions
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function escapeAttr(text) {
            if (text === null || text === undefined) return '';
            return String(text).replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function formatTimestamp(timestamp) {
            if (!timestamp) return '';
            const date = new Date(timestamp * 1000);
            return date.toLocaleString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit'
            });
        }

        function formatActivityContent(text) {
            if (!text) return '';

            let formatted = escapeHtml(text);

            formatted = formatted.replace(
                /@\[([^\]]+)\]\((\d+)\)/g,
                '<span class="mention-tag">@$1</span>'
            );

            const urlRegex = /(https?:\/\/[^\s<]+)/g;
            formatted = formatted.replace(
                urlRegex,
                '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
            );

            return formatted;
        }

        function showSuccessToast(message = 'Success!') {
            const toast = document.getElementById('success-toast');
            if (toast) {
                toast.textContent = message;
                toast.classList.add('show');
                setTimeout(() => {
                    toast.classList.remove('show');
                }, 3000);
            }
        }

        // Field rendering helpers
        function renderFieldIcon(field) {
            if (field.icon && !field.icon.includes('undefined')) {
                return `<img class="field-icon" src="${escapeHtml(field.icon)}" alt="" width="12" height="12">`;
            }
            if (field.font_icon && !field.font_icon.includes('undefined')) {
                return `<i class="${escapeHtml(field.font_icon)} field-icon"></i>`;
            }
            return '';
        }

        // Expose methods to template
        return {
            // State
            contacts,
            filteredContacts,
            selectedContactId,
            selectedContact,
            searchTerm,
            loading,
            detailsLoading,
            contactsCount,
            commentText,
            commentSubmitting,
            mentionUsers,
            mentionActiveIndex,
            showMentionDropdown,
            isMobileDetailsVisible,
            mobileActiveTab,

            // Methods
            loadContacts,
            selectContact,
            toggleActivityGroup,
            submitComment,
            selectMention,
            hideMentionDropdown,
            toggleEditMode,
            hideMobileDetails,

            // Helpers
            escapeHtml,
            escapeAttr,
            formatTimestamp,
            formatActivityContent,
            renderFieldIcon
        };
    },

    template: `
        <div class="my-contacts-container">
            <!-- Left Panel: Contacts List -->
            <div class="panel" id="contacts-panel" :class="{ 'mobile-hidden': isMobileDetailsVisible }">
                <div class="panel-header">
                    My Contacts <span id="contacts-count">({{ contactsCount }})</span>
                    <input type="text" class="search-input" id="contacts-search"
                           placeholder="Search contacts..."
                           v-model="searchTerm">
                </div>
                <div class="panel-content" id="contacts-list">
                    <div v-if="loading" class="loading">
                        <div class="loading-spinner" style="margin: 0 auto;"></div>
                        <p>Loading contacts...</p>
                    </div>
                    <div v-else-if="filteredContacts.length === 0" class="empty-state">
                        <p>No contacts found</p>
                    </div>
                    <template v-else>
                        <div v-for="contact in filteredContacts" :key="contact.ID"
                             class="contact-item"
                             :class="{ selected: selectedContactId === contact.ID }"
                             @click="selectContact(contact.ID)">
                            <div class="contact-name">
                                {{ contact.name }}
                                <span v-if="contact.overall_status" class="status-badge"
                                      :style="contact.overall_status_color ? 'background: ' + contact.overall_status_color + '20; color: ' + contact.overall_status_color : ''">
                                    {{ contact.overall_status }}
                                </span>
                            </div>
                            <div class="contact-meta">
                                <template v-if="contact.seeker_path">{{ contact.seeker_path }} • </template>
                                {{ contact.last_modified }}
                                <span class="source-badge">{{ contact.source === 'subassigned' ? 'Subassigned' : 'Assigned' }}</span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Right Panel: Contact Details -->
            <div class="panel" id="details-panel" :class="{ 'mobile-visible': isMobileDetailsVisible }">
                <div class="panel-header">
                    <button class="mobile-back-btn mobile-only" @click="hideMobileDetails">&larr;</button>
                    <span class="desktop-only header-contact-name">{{ selectedContact?.name || 'Contact Details' }}</span>
                    <span class="mobile-only" id="mobile-contact-name">{{ selectedContact?.name || '' }}</span>
                </div>
                <div class="mobile-tabs mobile-only" v-if="selectedContact && !selectedContact.error">
                    <button class="mobile-tab" :class="{ active: mobileActiveTab === 'details' }" @click="mobileActiveTab = 'details'">Details</button>
                    <button class="mobile-tab" :class="{ active: mobileActiveTab === 'comments' }" @click="mobileActiveTab = 'comments'">Comments</button>
                </div>
                <div class="panel-content" id="contact-details">
                    <!-- Loading state -->
                    <div v-if="detailsLoading" class="loading">
                        <div class="loading-spinner" style="margin: 0 auto;"></div>
                    </div>

                    <!-- Error state -->
                    <div v-else-if="selectedContact?.error" class="empty-state">
                        <p>Error: {{ selectedContact.error }}</p>
                    </div>

                    <!-- Empty state -->
                    <div v-else-if="!selectedContact" class="empty-state">
                        <div class="empty-state-icon">&#128100;</div>
                        <p>Select a contact to view details</p>
                    </div>

                    <!-- Contact details -->
                    <div v-else class="details-grid">
                        <div class="details-column" :class="{ 'mobile-tab-active': mobileActiveTab === 'details' }">
                            <!-- Tiles with fields -->
                            <template v-if="selectedContact.tiles && selectedContact.tiles.length > 0">
                                <div v-for="tile in selectedContact.tiles" :key="tile.key" class="detail-tile">
                                    <div class="tile-header">{{ tile.label }}</div>
                                    <div v-for="field in tile.fields" :key="field.key"
                                         class="detail-section"
                                         :data-field-key="field.key"
                                         :data-field-type="field.type"
                                         :data-contact-id="selectedContact.ID">
                                        <div class="detail-label">
                                            <span v-html="renderFieldIcon(field)"></span>{{ field.label }}
                                            <span class="edit-icon" @click="toggleEditMode(field.key)" title="Edit"><i class="mdi mdi-pencil"></i></span>
                                        </div>
                                        <div class="detail-value view-mode" :class="{ 'empty-value': !field.value }">
                                            {{ field.value || '' }}
                                        </div>
                                        <div class="edit-mode" v-html="field.component_html"></div>
                                    </div>
                                </div>
                            </template>
                            <p v-else class="detail-empty">No contact information available</p>

                            <!-- Record Info -->
                            <div class="detail-tile">
                                <div class="tile-header">Record Info</div>
                                <div v-if="selectedContact.created" class="detail-section">
                                    <div class="detail-label">Created</div>
                                    <div class="detail-value">{{ selectedContact.created }}</div>
                                </div>
                                <div v-if="selectedContact.last_modified" class="detail-section">
                                    <div class="detail-label">Last Modified</div>
                                    <div class="detail-value">{{ selectedContact.last_modified }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="details-column" :class="{ 'mobile-tab-active': mobileActiveTab === 'comments' }">
                            <h3 class="desktop-only">Comments & Activity</h3>
                            <div class="comment-input-section">
                                <div class="comment-input-wrapper">
                                    <div class="mention-dropdown" id="mention-dropdown" :class="{ show: showMentionDropdown }">
                                        <div v-for="(user, index) in mentionUsers" :key="user.ID"
                                             class="mention-item"
                                             :class="{ active: index === mentionActiveIndex }"
                                             @click="selectMention(user)">
                                            <span class="mention-name">{{ user.name }}</span>
                                        </div>
                                    </div>
                                    <textarea class="comment-textarea" id="comment-textarea"
                                              placeholder="Type your comment... Use @ to mention users"
                                              v-model="commentText"></textarea>
                                </div>
                                <div class="comment-actions">
                                    <button class="comment-submit-btn" id="comment-submit-btn"
                                            @click="submitComment"
                                            :disabled="commentSubmitting">
                                        {{ commentSubmitting ? 'Posting...' : 'Add Comment' }}
                                    </button>
                                </div>
                            </div>
                            <div class="activity-list">
                                <template v-if="selectedContact.groupedActivity && selectedContact.groupedActivity.length > 0">
                                    <template v-for="(item, index) in selectedContact.groupedActivity" :key="index">
                                        <!-- Comment -->
                                        <div v-if="item.type === 'comment'" class="activity-item type-comment">
                                            <span class="activity-author">{{ item.author }}</span>
                                            <span class="activity-date">{{ formatTimestamp(item.timestamp) }}</span>
                                            <div class="activity-content" v-html="formatActivityContent(item.content)"></div>
                                        </div>
                                        <!-- Activity group -->
                                        <div v-else class="activity-group" :id="'activity-group-' + index">
                                            <div class="activity-group-header" @click="toggleActivityGroup(index)">
                                                <span class="activity-group-arrow">▶</span>
                                                <span>{{ item.items.length }} field update{{ item.items.length > 1 ? 's' : '' }}</span>
                                            </div>
                                            <div class="activity-group-content">
                                                <div v-for="a in item.items" :key="a.id" class="activity-compact-item">
                                                    <span v-html="formatActivityContent(a.content)"></span>
                                                    <span class="activity-compact-author">{{ a.author }}</span>
                                                    <span class="activity-compact-date">{{ formatTimestamp(a.timestamp) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </template>
                                <p v-else class="detail-empty">No activity yet</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Success Toast -->
        <div class="success-toast" id="success-toast">
            Comment added successfully!
        </div>
    `
});

// Mount when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Replace the static HTML with Vue mount point
    const container = document.querySelector('.my-contacts-container');
    if (container) {
        // Clear the container and let Vue render
        const parent = container.parentNode;
        const vueRoot = document.createElement('div');
        vueRoot.id = 'my-contacts-app';
        parent.replaceChild(vueRoot, container);

        // Also move the toast outside the vue container
        const toast = document.getElementById('success-toast');
        if (toast) {
            parent.appendChild(toast);
        }

        MyContactsApp.mount('#my-contacts-app');
    }
});
