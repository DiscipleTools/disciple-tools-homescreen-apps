let selectedContactId = null;
let contacts = [];
let filteredContacts = [];

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    loadContacts();
});

// Handle dt:get-data events from DT web components (for typeahead search)
document.addEventListener('dt:get-data', async function(e) {
    if (!e.detail) return;

    const { field, query, onSuccess, onError, postType } = e.detail;

    try {
        const response = await fetch(
            `${myContactsApp.root}${myContactsApp.parts.root}/v1/${myContactsApp.parts.type}/field-options`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': myContactsApp.nonce
                },
                body: JSON.stringify({
                    parts: myContactsApp.parts,
                    field: field,
                    query: query || '',
                    post_type: postType || 'contacts'
                })
            }
        );

        const data = await response.json();

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

// Load contacts
async function loadContacts() {
    try {
        const response = await fetch(
            `${myContactsApp.root}${myContactsApp.parts.root}/v1/${myContactsApp.parts.type}/contacts`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': myContactsApp.nonce
                },
                body: JSON.stringify({
                    parts: myContactsApp.parts
                })
            }
        );
        const data = await response.json();
        contacts = data.contacts || [];
        filteredContacts = contacts;
        renderContacts();
    } catch (error) {
        console.error('Error loading contacts:', error);
        document.getElementById('contacts-list').innerHTML =
            '<div class="empty-state"><p>Error loading contacts</p></div>';
    }
}

// Render contacts list
function renderContacts() {
    const container = document.getElementById('contacts-list');
    const count = document.getElementById('contacts-count');

    count.textContent = `(${filteredContacts.length})`;

    if (filteredContacts.length === 0) {
        container.innerHTML = '<div class="empty-state"><p>No contacts found</p></div>';
        return;
    }

    container.innerHTML = filteredContacts.map(contact => {
        const statusStyle = contact.overall_status_color
            ? `background: ${contact.overall_status_color}20; color: ${contact.overall_status_color};`
            : '';

        const sourceLabel = contact.source === 'subassigned' ? 'Subassigned' : 'Assigned';

        return `
            <div class="contact-item ${parseInt(selectedContactId) === contact.ID ? 'selected' : ''}"
                 data-contact-id="${contact.ID}"
                 onclick="selectContact(${contact.ID})">
                <div class="contact-name">
                    ${escapeHtml(contact.name)}
                    ${contact.overall_status ? `<span class="status-badge" style="${statusStyle}">${escapeHtml(contact.overall_status)}</span>` : ''}
                </div>
                <div class="contact-meta">
                    ${contact.seeker_path ? escapeHtml(contact.seeker_path) + ' • ' : ''}
                    ${contact.last_modified ? escapeHtml(contact.last_modified) : ''}
                    <span class="source-badge">${sourceLabel}</span>
                </div>
            </div>
        `;
    }).join('');
}

// Filter contacts by search term
function filterContacts(searchTerm) {
    if (!searchTerm) {
        filteredContacts = contacts;
    } else {
        const term = searchTerm.toLowerCase();
        filteredContacts = contacts.filter(c =>
            c.name.toLowerCase().includes(term) ||
            (c.overall_status && c.overall_status.toLowerCase().includes(term)) ||
            (c.seeker_path && c.seeker_path.toLowerCase().includes(term))
        );
    }
    renderContacts();
}

// Select contact and show details
async function selectContact(contactId) {
    selectedContactId = contactId;

    // Highlight selected contact
    document.querySelectorAll('.contact-item').forEach(el => {
        el.classList.toggle('selected', parseInt(el.dataset.contactId) === contactId);
    });

    // Show details panel on mobile
    if (isMobile()) {
        document.getElementById('details-panel').classList.add('mobile-visible');
        document.getElementById('contacts-panel').classList.add('mobile-hidden');
    }

    // Show loading
    const detailsContainer = document.getElementById('contact-details');
    detailsContainer.innerHTML = '<div class="loading"><div class="loading-spinner" style="margin: 0 auto;"></div></div>';

    try {
        const response = await fetch(
            `${myContactsApp.root}${myContactsApp.parts.root}/v1/${myContactsApp.parts.type}/contact`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': myContactsApp.nonce
                },
                body: JSON.stringify({
                    contact_id: contactId,
                    parts: myContactsApp.parts
                })
            }
        );
        const contact = await response.json();

        if (contact.code) {
            detailsContainer.innerHTML = `<div class="empty-state"><p>Error: ${escapeHtml(contact.message || 'Unknown error')}</p></div>`;
            return;
        }

        renderContactDetails(contact);

        // Update mobile header
        document.getElementById('mobile-contact-name').textContent = contact.name;
    } catch (error) {
        console.error('Error loading contact details:', error);
        detailsContainer.innerHTML = '<div class="empty-state"><p>Error loading details</p></div>';
    }
}

// Render contact details
function renderContactDetails(contact) {
    const container = document.getElementById('contact-details');

    // Render tiles with their fields
    const tilesHtml = contact.tiles && contact.tiles.length > 0 ?
        contact.tiles.map(tile => `
            <div class="detail-tile">
                <div class="tile-header">${escapeHtml(tile.label)}</div>
                ${tile.fields.map(field => renderEditableField(field, contact.ID)).join('')}
            </div>
        `).join('') :
        '<p class="detail-empty">No contact information available</p>';

    // Render record info
    const recordInfoHtml = `
        <div class="detail-tile">
            <div class="tile-header">Record Info</div>
            ${contact.created ? `
                <div class="detail-section">
                    <div class="detail-label">Created</div>
                    <div class="detail-value">${escapeHtml(contact.created)}</div>
                </div>
            ` : ''}
            ${contact.last_modified ? `
                <div class="detail-section">
                    <div class="detail-label">Last Modified</div>
                    <div class="detail-value">${escapeHtml(contact.last_modified)}</div>
                </div>
            ` : ''}
        </div>
    `;

    // Render activity/comments timeline with grouped field updates
    const groupedActivity = groupActivityItems(contact.activity || []);

    const activityHtml = groupedActivity.length > 0 ?
        groupedActivity.map((item, index) => {
            if (item.type === 'comment') {
                // Render comment as prominent card
                return `
                    <div class="activity-item type-comment">
                        <span class="activity-author">${escapeHtml(item.author)}</span>
                        <span class="activity-date">${formatTimestamp(item.timestamp)}</span>
                        <div class="activity-content">${formatActivityContent(item.content)}</div>
                    </div>
                `;
            } else {
                // Render activity group as collapsible
                const count = item.items.length;
                const groupId = `activity-group-${index}`;
                return `
                    <div class="activity-group" id="${groupId}">
                        <div class="activity-group-header" onclick="toggleActivityGroup('${groupId}')">
                            <span class="activity-group-arrow">▶</span>
                            <span>${count} field update${count > 1 ? 's' : ''}</span>
                        </div>
                        <div class="activity-group-content">
                            ${item.items.map(a => `
                                <div class="activity-compact-item">
                                    ${formatActivityContent(a.content)}
                                    <span class="activity-compact-author">${escapeHtml(a.author)}</span>
                                    <span class="activity-compact-date">${formatTimestamp(a.timestamp)}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }
        }).join('') :
        '<p class="detail-empty">No activity yet</p>';

    container.innerHTML = `
        <div class="details-grid">
            <div class="details-column">
                <h2 class="contact-name-header">${escapeHtml(contact.name)}</h2>
                ${tilesHtml}
                ${recordInfoHtml}
            </div>

            <div class="details-column">
                <h3>Comments & Activity</h3>
                <div class="comment-input-section">
                    <div class="comment-input-wrapper">
                        <div class="mention-dropdown" id="mention-dropdown"></div>
                        <textarea class="comment-textarea" id="comment-textarea" placeholder="Type your comment... Use @ to mention users"></textarea>
                    </div>
                    <div class="comment-actions">
                        <button class="comment-submit-btn" id="comment-submit-btn" onclick="submitComment()">Add Comment</button>
                    </div>
                </div>
                <div class="activity-list">
                    ${activityHtml}
                </div>
            </div>
        </div>
    `;

    // Initialize DT components with their data (value, options)
    initializeDTComponents();

    // Initialize mention listeners
    initMentionListeners();
}

// Format activity content (mentions and links)
function formatActivityContent(text) {
    if (!text) return '';

    let formatted = escapeHtml(text);

    // Format @mentions: @[Name](id) -> styled span
    formatted = formatted.replace(
        /@\[([^\]]+)\]\((\d+)\)/g,
        '<span class="mention-tag">@$1</span>'
    );

    // Format URLs to clickable links
    const urlRegex = /(https?:\/\/[^\s<]+)/g;
    formatted = formatted.replace(
        urlRegex,
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>'
    );

    return formatted;
}

// Group consecutive activity items while keeping comments separate
function groupActivityItems(items) {
    const grouped = [];
    let currentGroup = null;

    items.forEach(item => {
        if (item.type === 'comment') {
            // Flush any pending activity group
            if (currentGroup) {
                grouped.push({ type: 'activity-group', items: currentGroup });
                currentGroup = null;
            }
            // Add comment as-is
            grouped.push(item);
        } else {
            // Accumulate activity items
            if (!currentGroup) currentGroup = [];
            currentGroup.push(item);
        }
    });

    // Flush remaining activity group
    if (currentGroup) {
        grouped.push({ type: 'activity-group', items: currentGroup });
    }

    return grouped;
}

// Toggle activity group expand/collapse
function toggleActivityGroup(groupId) {
    const group = document.getElementById(groupId);
    if (group) {
        group.classList.toggle('expanded');
    }
}

// Comment submission
async function submitComment() {
    const textarea = document.getElementById('comment-textarea');
    const submitBtn = document.getElementById('comment-submit-btn');
    const comment = textarea.value.trim();

    if (!comment || !selectedContactId) return;

    submitBtn.disabled = true;
    submitBtn.textContent = 'Posting...';

    try {
        const response = await fetch(
            `${myContactsApp.root}${myContactsApp.parts.root}/v1/${myContactsApp.parts.type}/comment`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': myContactsApp.nonce
                },
                body: JSON.stringify({
                    contact_id: selectedContactId,
                    comment: comment,
                    parts: myContactsApp.parts
                })
            }
        );

        const result = await response.json();

        if (result.success || result.comment_id) {
            textarea.value = '';
            selectContact(selectedContactId);
            showSuccessToast('Comment added successfully!');
        } else {
            alert('Failed to add comment: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error posting comment:', error);
        alert('Error posting comment');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Add Comment';
    }
}

// @mention functionality
let mentionSearchTimeout = null;
let mentionStartPos = -1;
let mentionUsers = [];
let mentionActiveIndex = 0;

function initMentionListeners() {
    const commentTextarea = document.getElementById('comment-textarea');
    const mentionDropdown = document.getElementById('mention-dropdown');

    if (!commentTextarea || !mentionDropdown) return;

    commentTextarea.addEventListener('input', function(e) {
        const text = this.value;
        const cursorPos = this.selectionStart;

        const textBeforeCursor = text.substring(0, cursorPos);
        const lastAtIndex = textBeforeCursor.lastIndexOf('@');

        if (lastAtIndex !== -1) {
            const textAfterAt = textBeforeCursor.substring(lastAtIndex + 1);

            if (!textAfterAt.includes(' ') && !textAfterAt.includes('\n')) {
                mentionStartPos = lastAtIndex;

                clearTimeout(mentionSearchTimeout);
                mentionSearchTimeout = setTimeout(() => {
                    searchMentionUsers(textAfterAt);
                }, 200);
                return;
            }
        }

        hideMentionDropdown();
    });

    commentTextarea.addEventListener('keydown', function(e) {
        const dropdown = document.getElementById('mention-dropdown');
        if (!dropdown || !dropdown.classList.contains('show')) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            mentionActiveIndex = Math.min(mentionActiveIndex + 1, mentionUsers.length - 1);
            renderMentionDropdown();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            mentionActiveIndex = Math.max(mentionActiveIndex - 1, 0);
            renderMentionDropdown();
        } else if (e.key === 'Enter' && mentionUsers.length > 0) {
            e.preventDefault();
            selectMention(mentionUsers[mentionActiveIndex]);
        } else if (e.key === 'Escape') {
            hideMentionDropdown();
        }
    });
}

async function searchMentionUsers(search) {
    if (search.length < 1) {
        hideMentionDropdown();
        return;
    }

    try {
        const response = await fetch(
            `${myContactsApp.root}${myContactsApp.parts.root}/v1/${myContactsApp.parts.type}/users-mention`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': myContactsApp.nonce
                },
                body: JSON.stringify({
                    search: search,
                    parts: myContactsApp.parts
                })
            }
        );

        const data = await response.json();
        mentionUsers = data.users || [];
        mentionActiveIndex = 0;

        if (mentionUsers.length > 0) {
            renderMentionDropdown();
            document.getElementById('mention-dropdown').classList.add('show');
        } else {
            hideMentionDropdown();
        }
    } catch (error) {
        console.error('Error searching users:', error);
        hideMentionDropdown();
    }
}

function renderMentionDropdown() {
    const dropdown = document.getElementById('mention-dropdown');
    if (!dropdown) return;

    dropdown.innerHTML = mentionUsers.map((user, index) => `
        <div class="mention-item ${index === mentionActiveIndex ? 'active' : ''}"
             onclick="selectMention(mentionUsers[${index}])">
            <span class="mention-name">${escapeHtml(user.display_name)}</span>
        </div>
    `).join('');
}

function selectMention(user) {
    const textarea = document.getElementById('comment-textarea');
    const text = textarea.value;
    const cursorPos = textarea.selectionStart;

    const beforeMention = text.substring(0, mentionStartPos);
    const afterCursor = text.substring(cursorPos);

    const mentionText = `@[${user.display_name}](${user.ID}) `;
    textarea.value = beforeMention + mentionText + afterCursor;

    const newCursorPos = beforeMention.length + mentionText.length;
    textarea.setSelectionRange(newCursorPos, newCursorPos);
    textarea.focus();

    hideMentionDropdown();
}

function hideMentionDropdown() {
    const dropdown = document.getElementById('mention-dropdown');
    if (dropdown) {
        dropdown.classList.remove('show');
    }
    mentionUsers = [];
    mentionStartPos = -1;
}

// Mobile functionality
function isMobile() {
    return window.innerWidth <= 768;
}

function hideMobileDetails() {
    document.getElementById('details-panel').classList.remove('mobile-visible');
    document.getElementById('contacts-panel').classList.remove('mobile-hidden');
}

// Store field data for programmatic initialization of components
window.fieldDataStore = window.fieldDataStore || {};

// Render an editable field with view and edit modes
function renderEditableField(field, contactId) {
    const rawValue = field.raw_value;

    // Determine default based on field type (arrays for multi-value fields)
    const isArrayField = ['multi_select', 'tags', 'communication_channel', 'connection', 'location', 'location_meta', 'link'].includes(field.type);
    const defaultValue = isArrayField ? [] : '';
    const valueForJson = (rawValue !== null && rawValue !== undefined) ? rawValue : defaultValue;

    // Filter out any options with invalid IDs or labels, and ensure all values are strings
    const validOptions = (field.options || []).filter(opt =>
        opt &&
        opt.id !== null &&
        opt.id !== undefined &&
        opt.id !== '' &&
        opt.label !== null &&
        opt.label !== undefined &&
        opt.label !== ''
    ).map(opt => ({
        // Ensure id and label are strings
        id: String(opt.id),
        label: String(opt.label),
        color: opt.color || null,
        icon: opt.icon || null
    }));

    // Store field data for later initialization
    const fieldId = `field-${contactId}-${field.key}`;
    window.fieldDataStore[fieldId] = {
        value: valueForJson,
        options: validOptions,
        type: field.type
    };

    return `
        <div class="detail-section" data-field-key="${escapeHtml(field.key)}" data-field-type="${escapeHtml(field.type)}" data-contact-id="${contactId}">
            <div class="detail-label">
                ${renderFieldIcon(field)}${escapeHtml(field.label)}
                <span class="edit-icon" onclick="toggleEditMode('${escapeHtml(field.key)}')" title="Edit">&#9998;</span>
            </div>
            <div class="detail-value view-mode ${!field.value ? 'empty-value' : ''}">${field.value ? escapeHtml(field.value) : '-'}</div>
            <div class="edit-mode">
                ${renderDTComponent(field, contactId)}
            </div>
        </div>
    `;
}

// Render the appropriate DT component based on field type
// Components that need complex data (arrays/objects) get a data-field-id for programmatic init
function renderDTComponent(field, contactId) {
    const fieldKey = escapeHtml(field.key);
    const fieldId = `field-${contactId}-${field.key}`;

    switch (field.type) {
        case 'text':
            return `<dt-text name="${fieldKey}" value="${escapeAttr(field.raw_value || '')}"></dt-text>`;

        case 'textarea':
            return `<dt-textarea name="${fieldKey}" value="${escapeAttr(field.raw_value || '')}"></dt-textarea>`;

        case 'number':
            return `<dt-number name="${fieldKey}" value="${escapeAttr(field.raw_value || '')}"></dt-number>`;

        case 'boolean':
            return `<dt-toggle name="${fieldKey}" ${field.raw_value ? 'checked' : ''}></dt-toggle>`;

        case 'date':
            return `<dt-date name="${fieldKey}" timestamp="${field.raw_value || ''}"></dt-date>`;

        case 'key_select':
            // key_select needs options set programmatically
            return `<dt-single-select data-field-id="${fieldId}" name="${fieldKey}"></dt-single-select>`;

        case 'multi_select':
            // multi_select needs value and options set programmatically
            return `<dt-multi-select data-field-id="${fieldId}" name="${fieldKey}"></dt-multi-select>`;

        case 'communication_channel':
            return `<dt-multi-text data-field-id="${fieldId}" name="${fieldKey}"></dt-multi-text>`;

        case 'tags':
            return `<dt-tags data-field-id="${fieldId}" name="${fieldKey}" allowAdd></dt-tags>`;

        case 'connection':
            return `<dt-connection data-field-id="${fieldId}" name="${fieldKey}" postType="${field.post_type || 'contacts'}"></dt-connection>`;

        case 'location':
        case 'location_meta':
            return `<dt-location data-field-id="${fieldId}" name="${fieldKey}"></dt-location>`;

        case 'user_select':
            // User select requires special permissions not available in magic link context
            return `<span class="detail-empty">Not editable in this view</span>`;

        default:
            return `<dt-text name="${fieldKey}" value="${escapeAttr(field.raw_value || '')}"></dt-text>`;
    }
}

// Initialize DT components with their data after HTML is inserted
function initializeDTComponents() {
    // Use requestAnimationFrame to ensure DOM is fully rendered
    requestAnimationFrame(() => {
        const components = document.querySelectorAll('[data-field-id]');

        components.forEach(async (component) => {
            const fieldId = component.dataset.fieldId;
            const tagName = component.tagName.toLowerCase();
            const data = window.fieldDataStore[fieldId];

            if (!data) {
                return;
            }

            try {
                // Wait for the custom element to be defined/upgraded
                if (customElements.get(tagName) === undefined) {
                    await customElements.whenDefined(tagName);
                }

                // Set properties directly on the component
                // IMPORTANT: Always set options first (even as empty array) before value
                // This prevents _filterOptions from failing when value triggers willUpdate
                component.options = data.options && data.options.length > 0 ? data.options : [];

                if (data.value !== null && data.value !== undefined) {
                    // For multi-select, ensure value is an array of valid strings
                    if (tagName === 'dt-multi-select' && Array.isArray(data.value)) {
                        const cleanValue = data.value
                            .filter(v => v !== null && v !== undefined && v !== '')
                            .map(v => String(v));
                        component.value = cleanValue;
                    } else {
                        component.value = data.value;
                    }
                }
            } catch (err) {
                console.error(`Error initializing component:`, err);
            }
        });
    });
}

// Toggle edit mode for a field
function toggleEditMode(fieldKey) {
    const section = document.querySelector(`.detail-section[data-field-key="${fieldKey}"]`);
    if (!section) return;

    const isEditing = section.classList.contains('editing');

    // Close any other open edit modes
    document.querySelectorAll('.detail-section.editing').forEach(el => {
        if (el !== section) {
            el.classList.remove('editing');
        }
    });

    if (isEditing) {
        section.classList.remove('editing');
    } else {
        section.classList.add('editing');
        // Initialize change listener for the component
        initFieldChangeListener(section);
    }
}

// Close edit mode when clicking outside
document.addEventListener('click', function(e) {
    // Don't close if clicking inside an editing section or its components
    const editingSection = document.querySelector('.detail-section.editing');
    if (!editingSection) return;

    // Check if click is inside the editing section
    if (editingSection.contains(e.target)) return;

    // Check if click is inside a dropdown/option list (these can be outside the section)
    if (e.target.closest('.option-list, ul[class*="option"], li[tabindex]')) return;

    // Close the editing section
    editingSection.classList.remove('editing');
});

// Initialize change listener for a field's DT component
function initFieldChangeListener(section) {
    const editMode = section.querySelector('.edit-mode');
    const component = editMode.querySelector('dt-text, dt-textarea, dt-number, dt-toggle, dt-date, dt-single-select, dt-multi-select, dt-multi-text, dt-tags, dt-connection, dt-location');

    if (!component || component.hasAttribute('data-listener-added')) return;

    component.setAttribute('data-listener-added', 'true');

    const fieldType = section.dataset.fieldType;

    component.addEventListener('change', async (e) => {
        const fieldKey = section.dataset.fieldKey;
        const contactId = section.dataset.contactId;
        const newValue = e.detail?.newValue ?? e.detail?.value ?? component.value;

        await saveFieldValue(contactId, fieldKey, fieldType, newValue, section);
    });
}

// Field types that allow multiple values - don't auto-close after saving
const multiValueFieldTypes = ['multi_select', 'connection', 'tags', 'location', 'location_meta', 'communication_channel'];

// Save field value to the server
async function saveFieldValue(contactId, fieldKey, fieldType, value, section) {
    section.classList.add('saving');

    try {
        const response = await fetch(
            `${myContactsApp.root}${myContactsApp.parts.root}/v1/${myContactsApp.parts.type}/update-field`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': myContactsApp.nonce
                },
                body: JSON.stringify({
                    contact_id: parseInt(contactId),
                    field_key: fieldKey,
                    field_value: value,
                    parts: myContactsApp.parts
                })
            }
        );

        const result = await response.json();

        if (result.success) {
            // Update the view mode value
            const viewMode = section.querySelector('.view-mode');
            const displayValue = result.value || '-';
            viewMode.textContent = displayValue;
            viewMode.classList.toggle('empty-value', !result.value);

            // Only auto-close for single-value fields, not multi-value fields
            if (!multiValueFieldTypes.includes(fieldType)) {
                section.classList.remove('editing');
            }
            showSuccessToast('Field updated');
        } else {
            const errorMsg = result.message || 'Failed to update field';
            alert(errorMsg);
        }
    } catch (error) {
        console.error('Error saving field:', error);
        alert('Error saving field');
    } finally {
        section.classList.remove('saving');
    }
}

// Escape HTML attribute
function escapeAttr(text) {
    if (text === null || text === undefined) return '';
    return String(text).replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Render field icon (image URL or font-icon class)
function renderFieldIcon(field) {
    // Check for image icon first (URL)
    if (field.icon && !field.icon.includes('undefined')) {
        return `<img class="field-icon" src="${escapeHtml(field.icon)}" alt="" width="12" height="12">`;
    }
    // Check for font icon (CSS class like mdi mdi-account)
    if (field.font_icon && !field.font_icon.includes('undefined')) {
        return `<i class="${escapeHtml(field.font_icon)} field-icon"></i>`;
    }
    return '';
}

// Utility: escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Format timestamp in browser's timezone
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

// Success toast
function showSuccessToast(message = 'Success!') {
    const toast = document.getElementById('success-toast');
    toast.textContent = message;
    toast.classList.add('show');
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}
