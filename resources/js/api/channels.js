/**
 * Channels API Service
 * 
 * Provides methods to fetch Channel courses and segments data
 * Uses mock data for development (will be replaced with actual API endpoints)
 */

import axios from 'axios';

// Mock data for development
const mockCourses = [
  {
    id: 101,
    title: "Channel Course 1",
    description: "This is a sample Channel course covering essential music concepts.",
    instructor: "Channel Instructor X",
    category: "Music Theory",
    published_at: "2023-09-10",
    duration_minutes: 220,
    thumbnail_url: "https://placehold.co/600x400?text=Channel+1",
    segments: getMockSegments(101)
  },
  {
    id: 102,
    title: "Channel Course 2",
    description: "This is a comprehensive Channel course on music production.",
    instructor: "Channel Instructor Y",
    category: "Production",
    published_at: "2023-10-25",
    duration_minutes: 180,
    thumbnail_url: "https://placehold.co/600x400?text=Channel+2",
    segments: getMockSegments(102)
  },
  {
    id: 103,
    title: "Channel Course 3",
    description: "This Channel course focuses on instrument mastery.",
    instructor: "Channel Instructor Z",
    category: "Instrument",
    published_at: "2023-12-15",
    duration_minutes: 250,
    thumbnail_url: "https://placehold.co/600x400?text=Channel+3",
    segments: getMockSegments(103)
  }
];

// Helper function to generate mock segments
function getMockSegments(courseId) {
  const segmentTitles = [
    'Introduction to Concepts',
    'Fundamental Techniques',
    'Advanced Applications',
    'Practice Methods',
    'Performance Tips',
    'Theory and Application',
    'Master Demonstrations',
    'Review and Assessment'
  ];
  
  const count = Math.floor(Math.random() * 6) + 3; // 3-8 segments
  const segments = [];
  
  for (let i = 1; i <= count; i++) {
    const randomTitle = segmentTitles[Math.floor(Math.random() * segmentTitles.length)];
    segments.push({
      id: (courseId * 100) + i,
      course_id: courseId,
      title: `Segment ${i}: ${randomTitle}`,
      description: "This segment covers key concepts and practical applications.",
      sequence_number: i,
      duration_minutes: Math.floor(Math.random() * 31) + 10, // 10-40 minutes
      video_url: `https://example.com/videos/channel-${courseId}/segment-${i}.mp4`,
      thumbnail_url: `https://placehold.co/400x225?text=Segment+${i}`
    });
  }
  
  return segments;
}

// Create API instance with config
const apiClient = axios.create({
  baseURL: 'https://api.channels.com', // Will be replaced with actual API endpoint
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
});

// API Service
const channelsApi = {
  /**
   * Get all Channel courses
   * @returns {Promise} Promise with courses data
   */
  getCourses() {
    // For development, return mock data
    return new Promise((resolve) => {
      setTimeout(() => {
        resolve({ data: mockCourses });
      }, 300); // Simulate network delay
    });
    
    // For production: 
    // return apiClient.get('/courses');
  },

  /**
   * Get a specific Channel course by ID
   * @param {number} id Course ID
   * @returns {Promise} Promise with course data
   */
  getCourse(id) {
    // For development, return mock data
    return new Promise((resolve, reject) => {
      setTimeout(() => {
        const course = mockCourses.find(c => c.id === parseInt(id));
        if (course) {
          resolve({ data: course });
        } else {
          reject(new Error('Course not found'));
        }
      }, 300); // Simulate network delay
    });
    
    // For production:
    // return apiClient.get(`/courses/${id}`);
  },

  /**
   * Import a segment from Channel to the transcription system
   * @param {number} segmentId Segment ID to import
   * @returns {Promise} Promise with import result
   */
  importSegment(segmentId) {
    // For development
    return new Promise((resolve) => {
      setTimeout(() => {
        resolve({ data: { success: true, message: 'Segment imported successfully' } });
      }, 500);
    });
    
    // For production:
    // return apiClient.post(`/segments/import/${segmentId}`);
  },

  /**
   * Import multiple segments in bulk
   * @param {Array} segmentIds Array of segment IDs to import
   * @returns {Promise} Promise with import result
   */
  importSegmentsBulk(segmentIds) {
    // For development
    return new Promise((resolve) => {
      setTimeout(() => {
        resolve({ 
          data: { 
            success: true, 
            count: segmentIds.length,
            message: `${segmentIds.length} segments imported successfully` 
          } 
        });
      }, 800);
    });
    
    // For production:
    // return apiClient.post('/segments/import-bulk', { segments: segmentIds });
  }
};

export default channelsApi; 