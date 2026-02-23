<script setup>
import { onMounted, ref } from 'vue'
import * as faceapi from 'face-api.js'

const videoRef = ref(null)
const canvasRef = ref(null)
const isModelLoaded = ref(false)

// 1. Load Model (Otak AI) dari folder public/models
const loadModels = async () => {
  console.log("Sedang memuat model...")
  try {
    // Kita pakai TinyFaceDetector biar ringan & cepat
    await faceapi.nets.tinyFaceDetector.loadFromUri('/models')
    await faceapi.nets.faceLandmark68Net.loadFromUri('/models')
    await faceapi.nets.faceRecognitionNet.loadFromUri('/models')
    
    console.log("Model BERHASIL dimuat!")
    isModelLoaded.value = true
    startVideo()
  } catch (error) {
    console.error("Gagal memuat model:", error)
    alert("Gagal memuat model wajah. Cek folder public/models!")
  }
}

// 2. Nyalakan Webcam
const startVideo = () => {
  navigator.mediaDevices.getUserMedia({ video: {} })
    .then(stream => {
      videoRef.value.srcObject = stream
    })
    .catch(err => console.error("Gagal akses kamera:", err))
}

// 3. Deteksi Wajah saat video jalan
const handlePlay = () => {
  const video = videoRef.value
  const canvas = canvasRef.value
  
  // Sesuaikan ukuran canvas dengan video
  const displaySize = { width: video.width, height: video.height }
  faceapi.matchDimensions(canvas, displaySize)

  // Loop deteksi setiap 100ms (biar gak berat)
  setInterval(async () => {
    // Deteksi wajah + titik wajah (landmarks)
    const detections = await faceapi.detectAllFaces(video, new faceapi.TinyFaceDetectorOptions())
      .withFaceLandmarks()

    // Gambar kotak di canvas
    const resizedDetections = faceapi.resizeResults(detections, displaySize)
    canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height)
    faceapi.draw.drawDetections(canvas, resizedDetections)
  }, 100)
}

onMounted(() => {
  loadModels()
})
</script>

<template>
  <div class="container">
    <h2>Test Kamera & Face API</h2>
    <p v-if="!isModelLoaded">Sedang memuat model AI...</p>
    <p v-else>Silakan arahkan wajah ke kamera!</p>

    <div class="video-wrapper">
      <video 
        ref="videoRef" 
        id="video" 
        width="640" 
        height="480" 
        autoplay 
        muted 
        @play="handlePlay"
      ></video>
      
      <canvas ref="canvasRef" id="canvas"></canvas>
    </div>
  </div>
</template>

<style scoped>
.container {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-top: 20px;
  font-family: Arial, sans-serif;
}

.video-wrapper {
  position: relative;
  display: flex;
  justify-content: center;
}

canvas {
  position: absolute;
  top: 0;
  left: 0;
}
</style>