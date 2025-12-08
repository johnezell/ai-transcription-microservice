<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { ref } from 'vue';

const props = defineProps({
    checks: Object,
    allHealthy: Boolean,
    environment: Object,
    timestamp: String,
});

const refreshing = ref(false);

function refresh() {
    refreshing.value = true;
    window.location.reload();
}

function getStatusColor(status) {
    switch (status) {
        case 'ok': return 'bg-emerald-500';
        case 'error': return 'bg-red-500';
        case 'skip': return 'bg-slate-400';
        default: return 'bg-yellow-500';
    }
}

function getStatusBg(status) {
    switch (status) {
        case 'ok': return 'bg-emerald-50 border-emerald-200';
        case 'error': return 'bg-red-50 border-red-200';
        case 'skip': return 'bg-slate-50 border-slate-200';
        default: return 'bg-yellow-50 border-yellow-200';
    }
}

function getStatusIcon(status) {
    switch (status) {
        case 'ok': return '✓';
        case 'error': return '✗';
        case 'skip': return '○';
        default: return '?';
    }
}
</script>

<template>
    <Head title="System Status" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="font-semibold text-xl text-gray-800 leading-tight">System Status</h2>
                    <p class="text-sm text-slate-500 mt-1">Connectivity and health checks</p>
                </div>
                <button
                    @click="refresh"
                    :disabled="refreshing"
                    class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition"
                >
                    <svg v-if="refreshing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-gray-500" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Refresh
                </button>
            </div>
        </template>

        <div class="py-6">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Overall Status Banner -->
                <div
                    :class="[
                        'rounded-xl p-6 mb-8 border-2',
                        allHealthy ? 'bg-emerald-50 border-emerald-300' : 'bg-red-50 border-red-300'
                    ]"
                >
                    <div class="flex items-center">
                        <div
                            :class="[
                                'flex-shrink-0 w-16 h-16 rounded-full flex items-center justify-center text-white text-3xl font-bold',
                                allHealthy ? 'bg-emerald-500' : 'bg-red-500'
                            ]"
                        >
                            {{ allHealthy ? '✓' : '!' }}
                        </div>
                        <div class="ml-6">
                            <h3 :class="['text-2xl font-bold', allHealthy ? 'text-emerald-800' : 'text-red-800']">
                                {{ allHealthy ? 'All Systems Operational' : 'Issues Detected' }}
                            </h3>
                            <p :class="['mt-1', allHealthy ? 'text-emerald-600' : 'text-red-600']">
                                Last checked: {{ new Date(timestamp).toLocaleString() }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Status Checks Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div
                        v-for="(check, key) in checks"
                        :key="key"
                        :class="['rounded-xl border-2 p-6 overflow-hidden', getStatusBg(check.status)]"
                    >
                        <div class="flex items-start">
                            <div
                                :class="[
                                    'flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center text-white text-lg font-bold',
                                    getStatusColor(check.status)
                                ]"
                            >
                                {{ getStatusIcon(check.status) }}
                            </div>
                            <div class="ml-4 flex-1 min-w-0">
                                <h4 class="text-lg font-semibold text-gray-900">{{ check.name }}</h4>
                                <p class="text-sm text-gray-600 mt-1">{{ check.message }}</p>

                                <!-- Details -->
                                <div v-if="check.details" class="mt-4 bg-white/50 rounded-lg p-4 overflow-hidden">
                                    <dl class="space-y-2 text-sm">
                                        <div v-for="(value, detailKey) in check.details" :key="detailKey" class="flex justify-between gap-2">
                                            <dt class="text-gray-500 capitalize shrink-0">{{ detailKey.replace(/_/g, ' ') }}:</dt>
                                            <dd class="text-gray-900 font-mono text-right min-w-0 truncate" :title="String(value)">
                                                <template v-if="value === null">—</template>
                                                <template v-else-if="typeof value === 'boolean'">{{ value ? 'Yes' : 'No' }}</template>
                                                <template v-else-if="typeof value === 'number'">{{ value.toLocaleString() }}</template>
                                                <template v-else>{{ value }}</template>
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Environment Info -->
                <div class="bg-white rounded-xl border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Environment</h3>
                    <dl class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div v-for="(value, key) in environment" :key="key" class="bg-gray-50 rounded-lg p-4">
                            <dt class="text-xs text-gray-500 uppercase tracking-wide">{{ key.replace(/_/g, ' ') }}</dt>
                            <dd class="mt-1 text-sm font-mono text-gray-900">
                                <template v-if="typeof value === 'boolean'">{{ value ? 'true' : 'false' }}</template>
                                <template v-else>{{ value }}</template>
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Quick Actions -->
                <div class="mt-8 flex flex-wrap gap-4">
                    <a
                        href="/status/json"
                        target="_blank"
                        class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg text-sm font-medium hover:bg-gray-700 transition"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                        </svg>
                        View JSON
                    </a>
                    <a
                        :href="route('dashboard')"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition"
                    >
                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                        Dashboard
                    </a>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

