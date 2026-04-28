<div class="text-sm text-gray-700 space-y-1">
    <div class="font-semibold">Available merge fields:</div>
    <ul class="list-disc list-inside space-y-1">
        <li><code>{{ '{{ student_name }}' }}</code></li>
        <li><code>{{ '{{ category }}' }}</code> (array or first value)</li>
        <li><code>{{ '{{ value }}' }}</code> (count or sum)</li>
        <li><code>{{ '{{ raw_value }}' }}</code> (pre-rollover count/sum)</li>
        <li><code>{{ '{{ cycle_value }}' }}</code> / <code>{{ '{{ cycle_index }}' }}</code> (rollover mode)</li>
        <li><code>{{ '{{ rollover_every }}' }}</code> / <code>{{ '{{ counter_mode }}' }}</code></li>
        <li><code>{{ '{{ threshold }}' }}</code></li>
        <li><code>{{ '{{ window_start }}' }}</code> / <code>{{ '{{ window_end }}' }}</code></li>
        <li><code>{{ '{{ staff_or_parent }}' }}</code> (label for audience)</li>
        <li><code>{{ '{{ as_of }}' }}</code> (log timestamp)</li>
    </ul>
</div>
