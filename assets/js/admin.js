(function($) {
    'use strict';
    
    let conversationHistory = [];
    
    $(document).ready(function() {
        const $chatForm = $('#dbq-chat-form');
        const $chatInput = $('#dbq-chat-input');
        const $chatMessages = $('#dbq-chat-messages');
        const $sendButton = $('.dbq-send-button');
        const $buttonText = $('.dbq-button-text');
        const $spinner = $('.dbq-spinner');
        const $queryDetails = $('#dbq-query-details');
        const $sqlDisplay = $('#dbq-sql-display');
        
        // Handle form submission
        $chatForm.on('submit', function(e) {
            e.preventDefault();
            
            const message = $chatInput.val().trim();
            
            if (!message) {
                return;
            }
            
            // Add user message to chat
            addMessage('user', message);
            
            // Clear input
            $chatInput.val('');
            
            // Show loading state
            setLoadingState(true);
            
            // Add loading message
            const $loadingMsg = $('<div class="dbq-loading">Thinking...</div>');
            $chatMessages.append($loadingMsg);
            scrollToBottom();
            
            // Prepare conversation history for API
            const apiHistory = conversationHistory.map(msg => ({
                role: msg.role,
                content: msg.content
            }));
            
            // Make AJAX request
            $.ajax({
                url: dbqData.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dbq_chat',
                    nonce: dbqData.nonce,
                    message: message,
                    history: JSON.stringify(apiHistory)
                },
                success: function(response) {
                    $loadingMsg.remove();
                    
                    if (response.success) {
                        // Add assistant response
                        addMessage('assistant', response.data.response);
                        
                        // Show SQL query if available
                        if (response.data.sql_query) {
                            $sqlDisplay.text(response.data.sql_query);
                            $queryDetails.show();
                            
                            // Store CSV data for download
                            if (response.data.csv_data) {
                                $('#dbq-download-csv').data('csv', response.data.csv_data).show();
                            } else {
                                $('#dbq-download-csv').hide();
                            }
                        }
                        
                        // Update conversation history
                        conversationHistory.push({
                            role: 'user',
                            content: message
                        });
                        conversationHistory.push({
                            role: 'assistant',
                            content: response.data.response
                        });
                        
                    } else {
                        addErrorMessage(response.data.message || dbqData.strings.error);
                    }
                },
                error: function(xhr, status, error) {
                    $loadingMsg.remove();
                    addErrorMessage(dbqData.strings.error + ' ' + error);
                },
                complete: function() {
                    setLoadingState(false);
                    scrollToBottom();
                }
            });
        });
        
        // Add message to chat
        function addMessage(role, content) {
            const $message = $('<div class="dbq-message ' + role + '"></div>');
            const $header = $('<div class="dbq-message-header"></div>');
            const $content = $('<div class="dbq-message-content"></div>');
            
            if (role === 'user') {
                $header.text('You');
            } else {
                $header.text('AI Assistant');
            }
            
            // Format content (handle code blocks, tables, etc.)
            let formattedContent = content;
            
            // Convert markdown code blocks to HTML
            formattedContent = formattedContent.replace(/```sql\n?([\s\S]*?)```/gi, '<pre><code>$1</code></pre>');
            formattedContent = formattedContent.replace(/```([\s\S]*?)```/gi, '<pre><code>$1</code></pre>');
            formattedContent = formattedContent.replace(/`([^`]+)`/gi, '<code>$1</code>');
            
            // Convert line breaks
            formattedContent = formattedContent.replace(/\n/g, '<br>');
            
            $content.html(formattedContent);
            
            $message.append($header);
            $message.append($content);
            
            // Remove welcome message if present
            $('.dbq-welcome-message').remove();
            
            $chatMessages.append($message);
            scrollToBottom();
        }
        
        // Add error message
        function addErrorMessage(message) {
            const $error = $('<div class="dbq-error">' + message + '</div>');
            $chatMessages.append($error);
            scrollToBottom();
        }
        
        // Set loading state
        function setLoadingState(loading) {
            if (loading) {
                $sendButton.prop('disabled', true);
                $buttonText.text(dbqData.strings.sending);
                $spinner.show();
            } else {
                $sendButton.prop('disabled', false);
                $buttonText.text('Send');
                $spinner.hide();
            }
        }
        
        // Scroll to bottom
        function scrollToBottom() {
            $chatMessages.scrollTop($chatMessages[0].scrollHeight);
        }
        
        // Allow Enter to submit (Shift+Enter for new line)
        $chatInput.on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                $chatForm.submit();
            }
        });
        
        // Focus input on load
        $chatInput.focus();
        
        // Handle CSV download
        $(document).on('click', '#dbq-download-csv', function() {
            const csvData = $(this).data('csv');
            if (!csvData) {
                return;
            }
            
            // Decode base64 CSV data
            const csvContent = atob(csvData);
            
            // Create blob and download
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'database-query-results-' + new Date().getTime() + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    });
})(jQuery);
