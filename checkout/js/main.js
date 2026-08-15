/**
 * Main JavaScript file for gov.br clone
 * Handles interactive functionality and user experience
 */

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('Gov.br clone initialized');
    
    // Initialize all functionality
    initializeSearch();
    initializeSharing();
    initializeAccessibility();
    initializeNavigation();
    initializeCookieSettings();
    initializeFooterInteractions();
    initializeLibrasButton();
});

/**
 * Search functionality
 */
function initializeSearch() {
    const searchToggleBtn = document.getElementById('search-toggle-btn');
    const searchBar = document.getElementById('search-bar');
    const searchCloseBtn = document.getElementById('search-close-btn');
    const headerSearchInput = document.getElementById('header-search-input');
    const headerSearchBtn = document.getElementById('header-search-btn');
    const voiceSearchBtn = document.getElementById('voice-search-btn');
    const searchModal = document.getElementById('search-modal');
    const searchForm = document.getElementById('search-form');
    const searchInput = document.getElementById('search-input');

    // Toggle search bar
    if (searchToggleBtn && searchBar) {
        searchToggleBtn.addEventListener('click', function() {
            searchBar.classList.toggle('hidden');
            if (!searchBar.classList.contains('hidden')) {
                headerSearchInput.focus();
            }
        });
    }

    // Close search bar
    if (searchCloseBtn && searchBar) {
        searchCloseBtn.addEventListener('click', function() {
            searchBar.classList.add('hidden');
            headerSearchInput.value = '';
        });
    }

    // Perform search
    function performSearch() {
        const query = headerSearchInput.value.trim();
        if (query) {
            window.location.href = `/busca?q=${encodeURIComponent(query)}`;
        }
    }

    // Search button click
    if (headerSearchBtn) {
        headerSearchBtn.addEventListener('click', performSearch);
    }

    // Search on Enter key
    if (headerSearchInput) {
        headerSearchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performSearch();
            }
        });
    }

    // Voice search (placeholder functionality)
    if (voiceSearchBtn) {
        voiceSearchBtn.addEventListener('click', function() {
            showNotification('Busca por voz ainda não implementada', 'info');
        });
    }
    if (searchModal) {
        searchModal.addEventListener('click', function(e) {
            if (e.target === searchModal) {
                searchModal.classList.add('hidden');
            }
        });
    }

    // Handle search form submission
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const query = searchInput.value.trim();
            
            if (query) {
                performSearch(query);
            }
        });
    }

    // Voice search functionality
    if (voiceSearchBtn) {
        voiceSearchBtn.addEventListener('click', function() {
            if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
                const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
                const recognition = new SpeechRecognition();
                
                recognition.lang = 'pt-BR';
                recognition.continuous = false;
                recognition.interimResults = false;

                recognition.onstart = function() {
                    voiceSearchBtn.innerHTML = '<i class="fas fa-microphone-slash text-red-600 mx-2"></i>';
                };

                recognition.onresult = function(event) {
                    const transcript = event.results[0][0].transcript;
                    searchInput.value = transcript;
                    performSearch(transcript);
                };

                recognition.onerror = function(event) {
                    console.error('Speech recognition error:', event.error);
                    showNotification('Erro na busca por voz. Tente novamente.', 'error');
                };

                recognition.onend = function() {
                    voiceSearchBtn.innerHTML = '<i class="fas fa-microphone text-blue-800 mx-2"></i>';
                };

                recognition.start();
            } else {
                showNotification('Busca por voz não suportada neste navegador.', 'warning');
            }
        });
    }

    // Escape key to close modal
    document.addEventListener('keydown', function(e) {
        const searchModal = document.getElementById('search-modal');
        if (e.key === 'Escape' && searchModal && !searchModal.classList.contains('hidden')) {
            searchModal.classList.add('hidden');
        }
    });
}

/**
 * Perform search operation
 */
function performSearch(query) {
    console.log('Searching for:', query);
    
    // In a real implementation, this would make an API call
    fetch(`/search?q=${encodeURIComponent(query)}`)
        .then(response => response.json())
        .then(data => {
            console.log('Search results:', data);
            showNotification(`Busca realizada: "${query}"`, 'success');
            
            // Close search modal
            const searchModal = document.getElementById('search-modal');
            if (searchModal) {
                searchModal.classList.add('hidden');
            }
        })
        .catch(error => {
            console.error('Search error:', error);
            showNotification('Erro na busca. Tente novamente.', 'error');
        });
}

/**
 * Social sharing functionality
 */
function initializeSharing() {
    const shareButtons = document.querySelectorAll('.share-btn');
    
    shareButtons.forEach(button => {
        button.addEventListener('click', function() {
            const platform = this.getAttribute('data-platform');
            const url = window.location.href;
            const title = document.title;
            const text = 'Confira esta informação do Governo Federal';
            
            switch (platform) {
                case 'facebook':
                    shareOnFacebook(url, title);
                    break;
                case 'twitter':
                    shareOnTwitter(url, text);
                    break;
                case 'whatsapp':
                    shareOnWhatsApp(url, text);
                    break;
                case 'link':
                    copyToClipboard(url);
                    break;
                default:
                    console.log('Unknown sharing platform:', platform);
            }
        });
    });
}

/**
 * Share on Facebook
 */
function shareOnFacebook(url, title) {
    const shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`;
    openShareWindow(shareUrl, 'Facebook');
}

/**
 * Share on Twitter
 */
function shareOnTwitter(url, text) {
    const shareUrl = `https://twitter.com/intent/tweet?url=${encodeURIComponent(url)}&text=${encodeURIComponent(text)}`;
    openShareWindow(shareUrl, 'Twitter');
}

/**
 * Share on WhatsApp
 */
function shareOnWhatsApp(url, text) {
    const shareUrl = `https://wa.me/?text=${encodeURIComponent(text + ' ' + url)}`;
    openShareWindow(shareUrl, 'WhatsApp');
}

/**
 * Copy link to clipboard
 */
function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showNotification('Link copiado com sucesso!', 'success');
        }).catch(err => {
            console.error('Failed to copy:', err);
            fallbackCopyToClipboard(text);
        });
    } else {
        fallbackCopyToClipboard(text);
    }
}

/**
 * Fallback copy to clipboard for older browsers
 */
function fallbackCopyToClipboard(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        document.execCommand('copy');
        showNotification('Link copiado com sucesso!', 'success');
    } catch (err) {
        console.error('Fallback copy failed:', err);
        showNotification('Erro ao copiar link. Tente manualmente.', 'error');
    }
    
    document.body.removeChild(textArea);
}

/**
 * Open share window
 */
function openShareWindow(url, platform) {
    const width = 600;
    const height = 400;
    const left = (window.innerWidth - width) / 2;
    const top = (window.innerHeight - height) / 2;
    
    window.open(
        url,
        `share-${platform}`,
        `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
    );
}

/**
 * Accessibility features
 */
function initializeAccessibility() {
    const accessibilityBtn = document.getElementById('accessibility-btn');
    
    if (accessibilityBtn) {
        accessibilityBtn.addEventListener('click', function() {
            toggleAccessibilityMenu();
        });
    }

    // Keyboard navigation support
    document.addEventListener('keydown', function(e) {
        // Skip to main content with Alt+M
        if (e.altKey && e.key === 'm') {
            e.preventDefault();
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                mainContent.focus();
                mainContent.scrollIntoView({ behavior: 'smooth' });
            }
        }
        
        // Toggle high contrast with Alt+C
        if (e.altKey && e.key === 'c') {
            e.preventDefault();
            toggleHighContrast();
        }
        
        // Increase font size with Alt+Plus
        if (e.altKey && e.key === '+') {
            e.preventDefault();
            increaseFontSize();
        }
        
        // Decrease font size with Alt+Minus
        if (e.altKey && e.key === '-') {
            e.preventDefault();
            decreaseFontSize();
        }
    });
}

/**
 * Toggle accessibility menu
 */
function toggleAccessibilityMenu() {
    // In a real implementation, this would show an accessibility panel
    const options = [
        'Aumentar fonte',
        'Diminuir fonte',
        'Alto contraste',
        'Leitor de tela',
        'Navegação por teclado'
    ];
    
    console.log('Accessibility options:', options);
    showNotification('Menu de acessibilidade ativado', 'info');
}

/**
 * Toggle high contrast mode
 */
function toggleHighContrast() {
    document.body.classList.toggle('high-contrast');
    const isHighContrast = document.body.classList.contains('high-contrast');
    
    localStorage.setItem('govbr-high-contrast', isHighContrast);
    showNotification(`Alto contraste ${isHighContrast ? 'ativado' : 'desativado'}`, 'info');
}

/**
 * Increase font size
 */
function increaseFontSize() {
    const currentSize = parseFloat(getComputedStyle(document.documentElement).fontSize);
    const newSize = Math.min(currentSize + 2, 24);
    document.documentElement.style.fontSize = newSize + 'px';
    
    localStorage.setItem('govbr-font-size', newSize);
    showNotification('Fonte aumentada', 'info');
}

/**
 * Decrease font size
 */
function decreaseFontSize() {
    const currentSize = parseFloat(getComputedStyle(document.documentElement).fontSize);
    const newSize = Math.max(currentSize - 2, 12);
    document.documentElement.style.fontSize = newSize + 'px';
    
    localStorage.setItem('govbr-font-size', newSize);
    showNotification('Fonte diminuída', 'info');
}

/**
 * Navigation functionality
 */
function initializeNavigation() {
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const menuBtn = document.getElementById('menu-btn');
    const appsBtn = document.getElementById('apps-btn');
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            toggleSidebar();
        });
    }
    
    if (menuBtn) {
        menuBtn.addEventListener('click', function() {
            toggleMainMenu();
        });
    }
    
    if (appsBtn) {
        appsBtn.addEventListener('click', function() {
            toggleAppsMenu();
        });
    }

    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
}

/**
 * Toggle sidebar menu
 */
function toggleSidebar() {
    console.log('Sidebar toggled');
    showNotification('Menu lateral ativado', 'info');
}

/**
 * Toggle main menu
 */
function toggleMainMenu() {
    console.log('Main menu toggled');
    showNotification('Menu principal ativado', 'info');
}

/**
 * Toggle apps menu
 */
function toggleAppsMenu() {
    console.log('Apps menu toggled');
    showNotification('Menu de aplicativos ativado', 'info');
}

/**
 * Cookie settings functionality
 */
function initializeCookieSettings() {
    const cookieSettingsBtn = document.getElementById('cookie-settings');
    
    if (cookieSettingsBtn) {
        cookieSettingsBtn.addEventListener('click', function() {
            resetCookieSettings();
        });
    }
    
    // Load saved preferences
    loadUserPreferences();
}

/**
 * Reset cookie settings
 */
function resetCookieSettings() {
    // Clear all gov.br related localStorage items
    const govbrKeys = Object.keys(localStorage).filter(key => key.startsWith('govbr-'));
    govbrKeys.forEach(key => localStorage.removeItem(key));
    
    // Reset visual preferences
    document.documentElement.style.fontSize = '';
    document.body.classList.remove('high-contrast');
    
    showNotification('Configurações de cookies redefinidas', 'success');
}

/**
 * Load user preferences from localStorage
 */
function loadUserPreferences() {
    // Load font size preference
    const savedFontSize = localStorage.getItem('govbr-font-size');
    if (savedFontSize) {
        document.documentElement.style.fontSize = savedFontSize + 'px';
    }
    
    // Load high contrast preference
    const highContrast = localStorage.getItem('govbr-high-contrast') === 'true';
    if (highContrast) {
        document.body.classList.add('high-contrast');
    }
}

/**
 * Footer interactions
 */
function initializeFooterInteractions() {
    const expandButtons = document.querySelectorAll('.footer-expand-btn');
    
    expandButtons.forEach(button => {
        button.addEventListener('click', function() {
            const icon = this.querySelector('i');
            const isExpanded = icon.classList.contains('fa-chevron-up');
            
            // Toggle icon
            if (isExpanded) {
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            } else {
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            }
            
            console.log('Footer section toggled');
        });
    });
}

/**
 * Show notification to user
 */
function showNotification(message, type = 'info') {
    const notification = document.getElementById('share-notification');
    
    if (notification) {
        const content = notification.querySelector('.notification-content');
        const icon = content.querySelector('i');
        const text = content.querySelector('span');
        
        // Update content
        text.textContent = message;
        
        // Update icon based on type
        icon.className = '';
        switch (type) {
            case 'success':
                icon.className = 'fas fa-check-circle text-green-500';
                break;
            case 'error':
                icon.className = 'fas fa-exclamation-circle text-red-500';
                break;
            case 'warning':
                icon.className = 'fas fa-exclamation-triangle text-yellow-500';
                break;
            default:
                icon.className = 'fas fa-info-circle text-blue-500';
        }
        
        // Show notification
        notification.classList.remove('hidden');
        
        // Auto-hide after 3 seconds
        setTimeout(() => {
            notification.classList.add('hidden');
        }, 3000);
    } else {
        // Fallback to console if notification element doesn't exist
        console.log(`[${type.toUpperCase()}] ${message}`);
    }
}

/**
 * Libras (Brazilian Sign Language) accessibility button
 */
function initializeLibrasButton() {
    const librasButton = document.getElementById('libras-button');
    
    if (librasButton) {
        const button = librasButton.querySelector('button');
        
        button.addEventListener('click', function() {
            // Show notification about Libras functionality
            showNotification('Recurso de acessibilidade em Libras ativado! Em breve disponível para interpretação em língua de sinais.', 'info');
            
            // Add visual feedback
            button.classList.add('animate-pulse');
            setTimeout(() => {
                button.classList.remove('animate-pulse');
            }, 2000);
        });
        
        // Ensure button stays visible during scroll
        window.addEventListener('scroll', function() {
            const scrolled = window.pageYOffset || document.documentElement.scrollTop;
            if (scrolled > 100) {
                librasButton.classList.add('opacity-90');
            } else {
                librasButton.classList.remove('opacity-90');
            }
        });
    }
}

/**
 * Utility function to debounce function calls
 */
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

/**
 * Handle responsive behavior
 */
function handleResponsive() {
    const isMobile = window.innerWidth < 768;
    
    // Adjust behavior for mobile devices
    if (isMobile) {
        // Mobile-specific functionality
        console.log('Mobile view activated');
    } else {
        // Desktop-specific functionality
        console.log('Desktop view activated');
    }
}

// Listen for window resize
window.addEventListener('resize', debounce(handleResponsive, 250));

// Initialize responsive behavior
handleResponsive();

// Service Worker registration for PWA capabilities (future enhancement)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // Service worker would be registered here for offline functionality
        console.log('Ready for service worker registration');
    });
}

// Performance monitoring
window.addEventListener('load', () => {
    // Log performance metrics
    if (window.performance && window.performance.timing) {
        const loadTime = window.performance.timing.loadEventEnd - window.performance.timing.navigationStart;
        console.log(`Page loaded in ${loadTime}ms`);
    }
});

// Error handling for unhandled promise rejections
window.addEventListener('unhandledrejection', (event) => {
    console.error('Unhandled promise rejection:', event.reason);
    showNotification('Ocorreu um erro inesperado. Tente novamente.', 'error');
});

// Global error handler
window.addEventListener('error', (event) => {
    console.error('Global error:', event.error);
    // Don't show notification for every error to avoid spam
});


