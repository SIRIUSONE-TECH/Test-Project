<template>
    <div class="container">
        <div class="row">
            <div class="col">
                <h1 class="text-center py-3">
                    Panda Test Task
                </h1>
            </div>
        </div>
        <div class="row">
            <div class="col">
                <form id="upload-form" @submit.prevent="uploadFile">
                    <div class="card">
                        <div class="card-header">Upload PDF File</div>
                        <div class="card-body">
                            <input type="file" name="file" class="form-control" @change="handleFileChange">
                            <div class="alert mt-4" :class="{'alert-success': !messageFail, 'alert-danger': messageFail}" v-if="message">
                                {{ message }}
                            </div>
                        </div>
                        <div class="card-footer">
                            <button type="submit" class="btn btn-success">
                                Upload
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="row mt-4" v-if="downloadUrl">
            <div class="col">
                <div class="card">
                    <div class="card-header">
                        The Excel output
                    </div>
                    <div class="card-body">
                        Download Excel file: <a :href="downloadUrl" download>{{ downloadUrl }}</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import axios from 'axios';

export default {
    data() {
        return {
            isUploading: false,
            message: '',
            messageFail: false,
            file: null,
            downloadUrl: ''
        };
    },
    methods: {
        handleFileChange(event) {
            this.file = event.target.files[0];
        },
        async uploadFile() {
            if (!this.file) {
                this.message = 'Please choose a PDF file';
                return;
            }

            const formData = new FormData();
            formData.append('file', this.file);

            try {
                const response = await axios.post('/invoice/upload', formData, {
                    headers: {
                        'Content-Type': 'multipart/form-data',
                    },
                });
                this.messageFail = false;
                this.message = response.data.message;
                this.downloadUrl = response.data.xlsxUrl;
            } catch (error) {
                this.messageFail = true;
                this.downloadUrl = '';
                
                if (error.response && error.response.data && error.response.data.message) {
                    this.message = error.response.data.message;
                } else {
                    this.message = 'File was not loaded';
                }

                console.error(error);
            }
        }
    }
}
</script>