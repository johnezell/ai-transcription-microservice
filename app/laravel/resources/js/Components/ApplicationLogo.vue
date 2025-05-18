<template>
    <svg
        viewBox="0 0 100 100"
        xmlns="http://www.w3.org/2000/svg"
        :class="$attrs.class"
        fill="currentColor"
    >
        <!-- Simple Vintage Camera with Sound Wave Logo -->
        <g>
            <!-- Camera Body -->
            <rect 
                x="15" 
                y="35" 
                width="50" 
                height="30" 
                rx="3" 
                fill="currentColor"
            />
            
            <!-- Camera Lens -->
            <circle 
                cx="40" 
                cy="50" 
                r="12" 
                fill="none" 
                stroke="currentColor" 
                stroke-width="4" 
            />
            <circle 
                cx="40" 
                cy="50" 
                r="6" 
                fill="none" 
                stroke="currentColor" 
                stroke-width="2" 
            />
            
            <!-- Camera Film Reel -->
            <circle 
                cx="22" 
                cy="42" 
                r="3" 
                fill="none" 
                stroke="currentColor" 
                stroke-width="1.5" 
            />
            
            <!-- Camera Handle -->
            <rect 
                x="15" 
                y="30" 
                width="10" 
                height="5" 
                rx="2" 
                fill="currentColor"
            />
            
            <!-- Camera Side Lens/Viewfinder -->
            <path 
                d="M65,45 L75,40 L75,60 L65,55 Z" 
                fill="currentColor"
            />
            
            <!-- Sound Waves -->
            <path 
                d="M80,35 Q85,50 80,65" 
                fill="none" 
                stroke="currentColor" 
                stroke-width="3" 
                stroke-linecap="round"
            />
            <path 
                d="M85,30 Q92,50 85,70" 
                fill="none" 
                stroke="currentColor" 
                stroke-width="3" 
                stroke-linecap="round"
            />
            
            <!-- Text line representing transcript -->
            <path 
                d="M20,75 L80,75" 
                stroke="currentColor" 
                stroke-width="3" 
                stroke-linecap="round"
            />
            <path 
                d="M30,82 L70,82" 
                stroke="currentColor" 
                stroke-width="3" 
                stroke-linecap="round"
            />
        </g>
    </svg>
</template>
