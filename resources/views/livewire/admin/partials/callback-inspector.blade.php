                                <p class="field-help">Recorded attempt fields retain the delivery evidence. The response label uses current configuration.</p>
                                <dl class="definition-list">
                                    <div><dt>Recorded target</dt><dd><code>{{ $redactor->redact($attempt->target_url ?? '[target unavailable]') }}</code></dd></div>
                                    <div><dt>Response / current label</dt><dd>#{{ $attempt->response_id ?? 'deleted' }} · {{ $redactor->redact($attempt->response?->external_label ?? 'Saved response') }}</dd></div>
                                    <div><dt>Request</dt><dd>{{ $attempt->request_log_id ?? 'No request reference' }}</dd></div>
                                    <div><dt>Outcome</dt><dd>{{ $attempt->error ? $redactor->redact($attempt->error) : ($attempt->http_status ? 'Receiver returned HTTP '.$attempt->http_status : 'No error detail recorded.') }}</dd></div>
                                    <div><dt>Lineage</dt><dd>{{ $attempt->retry_of_id ? 'Retry of #'.$attempt->retry_of_id : ($attempt->resend_of_id ? 'Resend of #'.$attempt->resend_of_id : 'Original attempt') }}</dd></div>
                                </dl>
                                <p class="field-help">Resend queues a new attempt using this attempt's captured target and payload. The original record is retained.</p>

